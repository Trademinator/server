<?php

namespace App\Domain\Research;

use App\Domain\Features\ContextFeatures;
use App\Domain\Features\FeatureEngine;
use App\Domain\Intelligence\ActionLabelAnalysis;
use App\Domain\Intelligence\ActionLabelReportStore;
use App\Domain\Intelligence\AlgorithmicLabelBuilder;
use App\Domain\Intelligence\AlgorithmicLabelSets;
use App\Domain\Intelligence\KnowledgeWindow;
use App\Domain\Intelligence\PatternCatalog;
use App\Domain\Intelligence\TrainingRuntime;
use App\Domain\MarketData\CandleProvenance;
use App\Domain\MarketData\CandleTimeframe;
use App\Domain\Operations\ActionLog;
use App\Models\MarketFeature;
use App\Repositories\TickerRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class DatasetSnapshotBuilder
{
    public function __construct(
        private DatasetStore $store,
        private TickerRepository $tickers,
        private ActionLabelAnalysis $actionAnalysis,
        private AlgorithmicLabelSets $labelSets,
        private ActionLabelReportStore $actionReports,
    ) {}

    /**
     * An internal caller may lend an already-held feature lock. The owner is
     * checked against this exact market; only the acquiring caller releases it.
     */
    public function build(string $exchange, string $symbol, string $period, LabelDefinition|SemanticLabels $definition,
        string $schema = 'core', array $custom = [], ?int $fromMs = null, ?int $toMs = null, ?int $asOfMs = null,
        ?string $featureLockOwner = null): array
    {
        $keys = FeatureSchema::keys($schema, $custom);
        $timeframe = new CandleTimeframe;
        $timeframe->next(0, $period);
        $asOfMs = min($asOfMs ?? PHP_INT_MAX, now()->getTimestampMs());
        $toMs = min($toMs ?? $asOfMs, $asOfMs);
        $fromMs ??= 0;
        if ($definition instanceof SemanticLabels) {
            $fromMs = max($fromMs, KnowledgeWindow::fromMs($asOfMs));
        }
        if ($exchange === '' || $symbol === '' || $fromMs < 0 || $fromMs > $toMs) {
            throw new InvalidArgumentException('Invalid market or decision-time range.');
        }
        $id = (string) Str::uuid7();
        $directory = $this->store->directory($id);
        $temporary = $directory.'.tmp';
        // Share M2's lock: a snapshot must not capture a partially rebuilt feature series.
        $lockName = 'trademinator:features:'.hash('sha256', "$exchange|$symbol|$period");
        $borrowedLock = $featureLockOwner !== null;
        $lock = $borrowedLock ? Cache::restoreLock($lockName, $featureLockOwner)
            : Cache::lock($lockName, TrainingRuntime::lockSeconds());
        if ($borrowedLock) {
            // Never reacquire or release a parent's lock. A token alone is not
            // permission: it must still own the lock for this exact market.
            if (! $lock->isOwnedByCurrentProcess()) {
                throw new RuntimeException('Feature lock ownership was lost before building the dataset. Retry the history rebuild.');
            }
        } elseif (! $lock->get()) {
            throw new RuntimeException('Features or a dataset are already being built for this market and period. Retry after that build finishes.');
        }
        $file = null;
        $started = microtime(true);
        try {
            TrainingRuntime::registerLock($lock);
            if (! mkdir($temporary, 0700, true)) {
                throw new RuntimeException('Cannot create private dataset directory.');
            }
            $file = fopen($temporary.'/rows.jsonl', 'xb');
            if ($file === false) {
                throw new RuntimeException('Cannot create dataset rows.');
            }
            // Every page observes the same source snapshot, including live candle corrections.
            if (DB::transactionLevel() === 0 && in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            }
            $manifest = DB::transaction(function () use ($exchange, $symbol, $period, $definition, $schema, $keys, $fromMs, $toMs, $asOfMs, $id, $directory, $temporary, $file, $timeframe, $started, $lock) {
                $query = MarketFeature::query()->where('exchange', $exchange)->where('symbol', $symbol)->where('period', $period)
                    ->where('version', FeatureEngine::VERSION)->whereBetween('available_at_ms', [$fromMs, $toMs])->orderBy('microtimestamp');
                $first = (clone $query)->first();
                if ($first === null) {
                    throw new RuntimeException('No M2 features in this range. Run trademinator:build-features first.');
                }

                $algorithmicActions = [];
                $algorithmicActionAvailability = [];
                $actionDiagnostics = null;
                $outcomeAvailable = true;
                if ($definition instanceof SemanticLabels) {
                    $set = $this->labelSets->find($exchange, $symbol, $period, $asOfMs);
                    if ($set === null) {
                        // Non-orchestrated CLI callers still receive a safe, on-demand build.
                        $set = app(AlgorithmicLabelBuilder::class)
                            ->build($exchange, $symbol, $period, $asOfMs);
                    }
                    $algorithmicActions = $set['action_labels'];
                    $algorithmicActionAvailability = $set['action_available_at_ms'];
                    $actionDiagnostics = $set['action'];
                    if ($set['outcome_horizon'] === null) {
                        $outcomeAvailable = false;
                    } else {
                        $definition = $definition->withHorizon($set['outcome_horizon']);
                    }
                    $frozenOutcomes = $set['outcome_labels'];
                    unset($set);
                }

                $candles = $this->tickers->streamHistory(
                    $exchange, $symbol, $period, (int) $first->microtimestamp, $asOfMs
                );
                $candles->rewind();
                $window = [];
                $past = $patternHistory = [];
                $history = null;
                if ($definition instanceof SemanticLabels && $outcomeAvailable) {
                    $historyStart = (int) $first->microtimestamp;
                    for ($i = 1; $i < $definition->lookback; $i++) {
                        $historyStart = max(0, $timeframe->previous($historyStart, $period));
                    }
                    $history = $this->tickers->streamHistory($exchange, $symbol, $period, $historyStart, $asOfMs);
                    $history->rewind();
                }
                $counts = array_fill_keys(['missing_features', 'missing_source', 'gaps', 'immature', 'unavailable_evidence'], 0);
                $reconstruction = ['rows_using_reconstructed_history' => 0, 'rows_with_reconstructed_candle' => 0, 'methods' => []];
                if ($definition instanceof SemanticLabels && $outcomeAvailable) {
                    $counts['semantic_warmup'] = 0;
                }
                $labels = array_fill_keys($definition instanceof SemanticLabels ? SemanticLabels::OUTCOMES : ['buy', 'sell', 'hodl'], 0);
                $hash = hash_init('sha256');
                $count = 0;
                $firstDecision = $lastDecision = null;
                foreach ($query->lazyById(500, column: 'microtimestamp', alias: 'microtimestamp') as $feature) {
                    TrainingRuntime::heartbeat();
                    $maxSeconds = config('research.dataset_max_seconds', 540);
                    if (microtime(true) - $started > $maxSeconds) {
                        throw new RuntimeException('Dataset build exceeded '.$maxSeconds.' seconds; use a smaller date range or increase RESEARCH_DATASET_MAX_SECONDS.');
                    }
                    $payload = $feature->payload;
                    $timestamp = $feature->microtimestamp;
                    $decision = $timeframe->next($timestamp, $period);
                    if (($payload['version'] ?? null) !== FeatureEngine::VERSION
                        || ($payload['microtimestamp'] ?? null) !== $timestamp
                        || ($payload['available_at_ms'] ?? null) !== $decision || $feature->available_at_ms !== $decision) {
                        throw new RuntimeException('M2 feature time/version mismatch; rebuild features.');
                    }
                    if ($history !== null) {
                        while ($history->valid() && (int) $history->key() <= $timestamp) {
                            $bar = $history->current();
                            $bar['microtimestamp'] = (int) $history->key();
                            $this->validateCandle($bar);
                            if ($past !== [] && $timeframe->next($past[array_key_last($past)]['microtimestamp'], $period) !== $bar['microtimestamp']) {
                                $past = [];
                                $patternHistory = [];
                            }
                            $past[] = $bar;
                            $past = array_slice($past, -$definition->lookback);
                            $history->next();
                        }
                        $current = $past === [] ? null : $past[array_key_last($past)];
                        if (($current['microtimestamp'] ?? null) === $timestamp) {
                            $patternHistory[] = ['candle' => $current, 'features' => $payload['features']];
                            $patternHistory = array_slice($patternHistory, -2);
                        }
                        if (count($past) < $definition->lookback) {
                            $counts['semantic_warmup']++;

                            continue;
                        }
                    }
                    if (($payload['source_available_at_ms'] ?? $decision) > $decision) {
                        $counts['unavailable_evidence']++;

                        continue;
                    }
                    $vector = FeatureSchema::vector($payload, $keys);
                    if ($vector === null) {
                        $counts['missing_features']++;

                        continue;
                    }
                    if ($definition instanceof SemanticLabels && ! $outcomeAvailable) {
                        $actionLabel = $algorithmicActions[$timestamp] ?? null;
                        $actionAvailable = $algorithmicActionAvailability[$timestamp] ?? null;
                        if ($actionLabel === null || ! is_int($actionAvailable) || $actionAvailable > $asOfMs) {
                            continue;
                        }
                        $row = [
                            'microtimestamp' => $timestamp,
                            'decision_at_ms' => $decision,
                            'label_available_at_ms' => $actionAvailable,
                            'vector' => $vector,
                            'label' => null,
                            'action_label' => $actionLabel,
                            'action_label_available_at_ms' => $actionAvailable,
                            'gross_return' => null,
                            'patterns' => [],
                            'source' => [
                                'feature_id' => $feature->getKey(),
                                'history_start_ms' => $payload['history_start_ms'] ?? null,
                                'context_snapshot_id' => $payload['context_snapshot_id'] ?? null,
                                'feature_sha256' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
                            ],
                        ];
                        $line = json_encode($row, JSON_THROW_ON_ERROR)."\n";
                        DatasetStore::write($file, $line);
                        hash_update($hash, $line);
                        $count++;
                        $firstDecision ??= $decision;
                        $lastDecision = $decision;

                        continue;
                    }
                    $window = array_values(array_filter($window, fn ($bar) => $bar['microtimestamp'] >= $timestamp));
                    while ($candles->valid() && (int) $candles->key() < $timestamp) {
                        $candles->next();
                    }
                    while (count($window) <= $definition->horizon && $candles->valid()) {
                        $raw = $candles->current();
                        $raw['microtimestamp'] = (int) $candles->key();
                        $window[] = $raw;
                        $candles->next();
                    }
                    if (($window[0]['microtimestamp'] ?? null) !== $timestamp) {
                        $counts['missing_source']++;

                        continue;
                    }
                    if (count($window) <= $definition->horizon
                        || $timeframe->next($window[$definition->horizon]['microtimestamp'], $period) > $asOfMs) {
                        $counts['immature']++;

                        continue;
                    }
                    $expected = $timestamp;
                    foreach ($window as $bar) {
                        if ($bar['microtimestamp'] !== $expected) {
                            $counts['gaps']++;

                            continue 2;
                        }
                        $this->validateCandle($bar);
                        $expected = $timeframe->next($expected, $period);
                    }
                    $labelAvailable = $expected;
                    foreach ($window as $bar) {
                        $labelAvailable = max($labelAvailable, CandleProvenance::availableAt($bar, $period));
                    }
                    if ($labelAvailable > $asOfMs) {
                        $counts['immature']++;

                        continue;
                    }
                    if (! isset($payload['close']) || (float) $payload['close'] !== (float) $window[0]['close']) {
                        throw new RuntimeException('Source candles changed since M2 features were built; rebuild features.');
                    }
                    $entry = $window[1];
                    $exit = $window[$definition->horizon];
                    $label = $definition instanceof SemanticLabels
                        ? ($frozenOutcomes[$timestamp] ?? null)
                        : $definition->label((float) $entry['open'], (float) $exit['close']);
                    if ($label === null) {
                        $counts['immature']++;

                        continue;
                    }
                    if ($definition instanceof SemanticLabels) {
                        $label['action'] = $label['label'];
                        unset($label['label']);
                    }
                    $row = [
                        'microtimestamp' => $timestamp, 'decision_at_ms' => $decision,
                        'entry_at_ms' => $entry['microtimestamp'], 'label_available_at_ms' => $labelAvailable,
                        'vector' => $vector, 'label' => $label['action'],
                        'action_label' => $definition instanceof SemanticLabels && isset($algorithmicActionAvailability[$timestamp])
                            ? ($algorithmicActions[$timestamp] ?? null) : null,
                        'action_label_available_at_ms' => $definition instanceof SemanticLabels
                            ? ($algorithmicActionAvailability[$timestamp] ?? null) : null,
                        'entry_price' => (string) $entry['open'], 'exit_price' => (string) $exit['close'],
                        'gross_return' => $label['gross_return'],
                        'source' => ['feature_id' => $feature->getKey(), 'history_start_ms' => $payload['history_start_ms'] ?? null,
                            'context_snapshot_id' => $payload['context_snapshot_id'] ?? null,
                            'reconstruction' => ['candle' => $window[0]['reconstruction'] ?? null,
                                'feature_history_counts' => $payload['reconstruction_counts'] ?? [],
                                'label_window_counts' => CandleProvenance::counts($window)],
                            'feature_sha256' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
                            'candle_window_sha256' => hash('sha256', json_encode($window, JSON_THROW_ON_ERROR))],
                    ];
                    if ($definition instanceof SemanticLabels) {
                        // M4 semantic rows describe public price movement only. They
                        // intentionally carry no Client fee, spread or slippage model.
                        $row['buy_price_return'] = $label['buy_price_return'];
                        $row['sell_base_price_return'] = $label['sell_base_price_return'];
                        $row['source']['semantic_history_sha256'] = hash('sha256', json_encode($past, JSON_THROW_ON_ERROR));
                        $row['semantic'] = $label['semantic'];
                        $row['candle'] = $window[0];
                        $row['patterns'] = (new PatternCatalog)->observations($patternHistory, $window, $period);
                        // Freeze optional context independently of the selected KNN schema.
                        // A Core dataset still has a 15-column KNN vector.
                        $row['context_features'] = array_intersect_key(
                            $payload['features'] ?? [], array_flip(ContextFeatures::KEYS)
                        );
                    } else {
                        $row['buy_net_return'] = $label['buy_net_return'];
                        $row['sell_base_net_return'] = $label['sell_base_net_return'];
                    }
                    $line = json_encode($row, JSON_THROW_ON_ERROR)."\n";
                    DatasetStore::write($file, $line);
                    hash_update($hash, $line);
                    if ($row['label'] !== null) {
                        $labels[$row['label']]++;
                    }
                    if (($payload['reconstruction_counts'] ?? []) !== []) {
                        $reconstruction['rows_using_reconstructed_history']++;
                        foreach (array_keys($payload['reconstruction_counts']) as $usedMethod) {
                            $reconstruction['methods'][$usedMethod] = ($reconstruction['methods'][$usedMethod] ?? 0) + 1;
                        }
                    }
                    $method = CandleProvenance::method($window[0]);
                    if ($method !== null) {
                        $reconstruction['rows_with_reconstructed_candle']++;
                    }
                    $count++;
                    $firstDecision ??= $decision;
                    $lastDecision = $decision;
                    if (! ($definition instanceof SemanticLabels) && $count > config('research.max_rows')) {
                        throw new RuntimeException('Dataset exceeds research.max_rows; choose a smaller --from/--to range.');
                    }
                }
                if ($count === 0) {
                    throw new RuntimeException('No eligible labelled rows: '.json_encode($counts).'. Collect more history or choose a schema with available features.');
                }
                if (! fflush($file)) {
                    throw new RuntimeException('Could not flush dataset rows.');
                }
                $manifest = [
                    'knn_schema_version' => FeatureSchema::VERSION,
                    'dataset_id' => $id, 'format_version' => 'm3-dataset-v1', 'created_at' => now()->toIso8601String(),
                    'exchange' => $exchange, 'symbol' => $symbol, 'period' => $period,
                    'feature_version' => FeatureEngine::VERSION, 'schema' => $schema, 'keys' => $keys,
                    'missing_policy' => 'drop_selected_missing', 'normalization' => 'fixed_m2_v1_no_fitting',
                    'feature_boundary_tolerance' => FeatureSchema::BOUNDARY_TOLERANCE,
                    'label_definition' => $definition->metadata(), 'from_ms' => $fromMs, 'to_ms' => $toMs, 'as_of_ms' => $asOfMs,
                    'first_decision_at_ms' => $firstDecision, 'last_decision_at_ms' => $lastDecision,
                    'rows' => $count, 'label_counts' => $labels, 'skipped' => $counts,
                    'outcome_available' => $outcomeAvailable,
                    'action_label_analysis' => $actionDiagnostics,
                    'reconstruction' => $reconstruction,
                    'rows_sha256' => hash_final($hash),
                ];
                $json = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n";
                TrainingRuntime::heartbeat(true);
                if (! $lock->isOwnedByCurrentProcess()) {
                    throw new RuntimeException('Feature lock ownership was lost before publishing the dataset.');
                }
                if (file_put_contents($temporary.'/manifest.json', $json) !== strlen($json) || ! rename($temporary, $directory)) {
                    throw new RuntimeException('Could not publish dataset snapshot.');
                }
                DB::table('research_datasets')->insert(['dataset_id' => $id, 'manifest' => json_encode($manifest, JSON_THROW_ON_ERROR), 'created_at' => now()]);
                // Publish atomically with the immutable dataset record. Never
                // replace a more recent CLI or intelligence-build analysis.
                if ($actionDiagnostics !== null) {
                    $this->actionReports->publish($exchange, $symbol, $period, $actionDiagnostics, $id);
                }

                return $manifest;
            });

            app(ActionLog::class)->write('dataset.saved', ['dataset_id' => $id,
                'exchange' => $exchange, 'symbol' => $symbol, 'period' => $period,
                'rows' => $manifest['rows'], 'outcome' => 'completed']);

            return $manifest;
        } catch (Throwable $error) {
            foreach ([$temporary, $directory] as $path) {
                foreach (['rows.jsonl', 'manifest.json'] as $name) {
                    if (is_file($path.'/'.$name)) {
                        unlink($path.'/'.$name);
                    }
                }
                if (is_dir($path)) {
                    rmdir($path);
                }
            }
            throw $error;
        } finally {
            if (is_resource($file)) {
                fclose($file);
            }
            TrainingRuntime::unregisterLock($lock);
            if (! $borrowedLock) {
                $lock->release();
            }
        }
    }

    private function validateCandle(array $bar): void
    {
        foreach (['open', 'high', 'low', 'close', 'volume'] as $key) {
            if (! is_numeric($bar[$key] ?? null) || ! is_finite((float) $bar[$key]) || $bar[$key] < 0
                || ($key !== 'volume' && $bar[$key] == 0)) {
                throw new RuntimeException('Invalid source candle: '.$key);
            }
        }
        if ($bar['high'] < max($bar['open'], $bar['close'], $bar['low']) || $bar['low'] > min($bar['open'], $bar['close'])) {
            throw new RuntimeException('Invalid source candle bounds.');
        }
    }
}
