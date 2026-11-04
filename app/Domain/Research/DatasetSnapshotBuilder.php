<?php

namespace App\Domain\Research;

use App\Domain\Features\ContextFeatures;
use App\Domain\Features\FeatureEngine;
use App\Domain\Intelligence\ActionLabelAnalysis;
use App\Domain\Intelligence\ActionLabelReportStore;
use App\Domain\Intelligence\AlgorithmicLabelBuilder;
use App\Domain\Intelligence\AlgorithmicLabelSets;
use App\Domain\Intelligence\BuildCheckpoint;
use App\Domain\Intelligence\BuildPaused;
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
        ?string $featureLockOwner = null, ?float $deadline = null, ?string $generation = null): array
    {
        $keys = FeatureSchema::keys($schema, $custom);
        $timeframe = new CandleTimeframe;
        $timeframe->next(0, $period);
        $asOfMs = min($asOfMs ?? PHP_INT_MAX, now()->getTimestampMs());
        $toMs = min($toMs ?? $asOfMs, $asOfMs);
        $fromMs ??= 0;
        if ($definition instanceof SemanticLabels) {
            $fromMs = max($fromMs, KnowledgeWindow::trainingFromMs());
        }
        if ($exchange === '' || $symbol === '' || $fromMs < 0 || $fromMs > $toMs) {
            throw new InvalidArgumentException('Invalid market or decision-time range.');
        }
        $checkpoint = null;
        $temporary = $directory = null;
        // Share M2's lock: a snapshot must not capture a partially rebuilt feature series.
        $lockName = 'trademinator:features:'.hash('sha256', "$exchange|$symbol|$period");
        $borrowedLock = $featureLockOwner !== null;
        $lock = $borrowedLock ? Cache::restoreLock($lockName, $featureLockOwner)
            : Cache::lock($lockName, BuildCheckpoint::LEASE_SECONDS);
        if ($borrowedLock) {
            // Never reacquire or release a parent's lock. A token alone is not
            // permission: it must still own the lock for this exact market.
            if (! $lock->isOwnedByCurrentProcess()) {
                throw new RuntimeException('Feature lock ownership was lost before building the dataset. Retry the history rebuild.');
            }
        } elseif (! $lock->get()) {
            throw new BuildPaused('Features or a dataset are already being built for this market and period. Retry after that build finishes.');
        }
        $file = null;
        $started = microtime(true);
        try {
            if ($borrowedLock) {
                TrainingRuntime::registerLock($lock);
            } else {
                TrainingRuntime::registerLease('snapshot-features:'.$lock->owner(),
                    fn (): bool => $lock->refresh(BuildCheckpoint::LEASE_SECONDS)
                        || $lock->refresh(BuildCheckpoint::LEASE_SECONDS + 1));
            }
            $checkpoint = new BuildCheckpoint(['kind' => 'snapshot', 'exchange' => $exchange, 'symbol' => $symbol,
                'period' => $period, 'as_of_ms' => $asOfMs, 'from_ms' => $fromMs, 'to_ms' => $toMs,
                'schema' => $schema, 'keys' => $keys, 'definition' => $definition->metadata(),
                'generation' => $generation,
                'profile' => $this->labelSets->profile($exchange, $symbol),
                'implementation' => BuildCheckpoint::implementation('snapshot')], $deadline);
            $revision = $this->labelSets->revision($exchange, $symbol, $period);
            $featureHash = $this->featureFingerprint($exchange, $symbol, $period, $fromMs, $toMs);
            if ($checkpoint->state !== [] && ($checkpoint->state['feature_hash'] !== $featureHash
                || ! $this->labelSets->unchangedThrough($exchange, $symbol, $period, $asOfMs,
                    $checkpoint->state['revision'], $revision))) {
                $checkpoint->reset();
            }
            if ($checkpoint->state === []) {
                $checkpoint->state = ['stage' => 'snapshot_rows', 'id' => (string) Str::uuid7(),
                    'feature_hash' => $featureHash, 'revision' => $revision, 'output_chunks' => 0];
                $checkpoint->save(false);
            }
            $id = $checkpoint->state['id'];
            if (DB::table('research_datasets')->where('dataset_id', $id)->exists()) {
                return $this->store->manifest($id);
            }
            $directory = $this->store->directory($id);
            $temporary = $directory.'.tmp.'.Str::uuid7();
            if (! mkdir($temporary, 0700, true)) {
                throw new RuntimeException('Cannot create private dataset directory.');
            }
            $file = fopen($temporary.'/rows.jsonl', 'x+b');
            if ($file === false) {
                throw new RuntimeException('Cannot create dataset rows.');
            }
            $hash = hash_init('sha256');
            $restored = 0;
            foreach ($checkpoint->chunks('snapshot-rows') as $bytes) {
                DatasetStore::write($file, $bytes);
                hash_update($hash, $bytes);
                $restored++;
            }
            if ($restored !== $checkpoint->state['output_chunks']) {
                throw new RuntimeException('Snapshot checkpoint chunk count mismatch.');
            }
            $savedOffset = ftell($file);
            $checkpoint->onSave('snapshot-rows', function () use ($checkpoint, $file, &$savedOffset): void {
                $end = ftell($file);
                if ($end > $savedOffset) {
                    fseek($file, $savedOffset);
                    $bytes = stream_get_contents($file, $end - $savedOffset);
                    if ($bytes === false || strlen($bytes) !== $end - $savedOffset) {
                        throw new RuntimeException('Cannot save snapshot checkpoint rows.');
                    }
                    $checkpoint->put('snapshot-rows', $checkpoint->state['output_chunks']++, $bytes);
                    $savedOffset = $end;
                }
            });
            // Each committed batch is guarded by the history ledger. Feature writes share the held lock.
            $manifest = (function () use ($exchange, $symbol, $period, $definition, $schema, $keys, $fromMs, $toMs, $asOfMs, $id, $directory, $temporary, $file, $timeframe, $started, $lock, $checkpoint, $hash, $deadline) {
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
                            ->build($exchange, $symbol, $period, $asOfMs, deadline: $deadline);
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

                $checkpoint->state['snapshot'] ??= [
                    'window' => [], 'past' => [], 'pattern_history' => [], 'source_through' => null,
                    'feature_through' => null, 'counts' => array_fill_keys(['missing_features', 'missing_source', 'gaps', 'immature', 'unavailable_evidence'], 0),
                    'reconstruction' => ['rows_using_reconstructed_history' => 0, 'rows_with_reconstructed_candle' => 0, 'methods' => []],
                    'labels' => array_fill_keys($definition instanceof SemanticLabels ? SemanticLabels::OUTCOMES : ['buy', 'sell', 'hodl'], 0),
                    'count' => 0, 'first_decision' => null, 'last_decision' => null,
                ];
                $saved = &$checkpoint->state['snapshot'];
                $window = &$saved['window'];
                $past = &$saved['past'];
                $patternHistory = &$saved['pattern_history'];
                $counts = &$saved['counts'];
                $reconstruction = &$saved['reconstruction'];
                $labels = &$saved['labels'];
                $count = &$saved['count'];
                $firstDecision = &$saved['first_decision'];
                $lastDecision = &$saved['last_decision'];
                $candles = $this->tickers->streamHistory($exchange, $symbol, $period,
                    $saved['source_through'] === null ? (int) $first->microtimestamp : $saved['source_through'] + 1, $asOfMs);
                $candles->rewind();
                $history = null;
                if ($definition instanceof SemanticLabels && $outcomeAvailable) {
                    $historyStart = (int) $first->microtimestamp;
                    for ($i = 1; $i < $definition->lookback; $i++) {
                        $historyStart = max(0, $timeframe->previous($historyStart, $period));
                    }
                    if ($past !== []) {
                        $historyStart = $past[array_key_last($past)]['microtimestamp'] + 1;
                    }
                    $history = $this->tickers->streamHistory($exchange, $symbol, $period, $historyStart, $asOfMs);
                    $history->rewind();
                    $counts['semantic_warmup'] ??= 0;
                }
                if ($saved['feature_through'] !== null) {
                    $query->where('microtimestamp', '>', $saved['feature_through']);
                }
                $attemptFeatures = 0;
                foreach ($query->lazyById(500, column: 'microtimestamp', alias: 'microtimestamp') as $feature) {
                    TrainingRuntime::heartbeat();
                    if ($checkpoint->due()) {
                        $this->validateRevision($checkpoint, $exchange, $symbol, $period, $asOfMs);
                        $checkpoint->save();
                    }
                    $maxSeconds = config('research.dataset_max_seconds', 540);
                    if ($attemptFeatures++ > 0 && microtime(true) - $started > $maxSeconds) {
                        $checkpoint->save(false);
                        throw new BuildPaused('Snapshot progress saved at its per-attempt time limit.');
                    }
                    $payload = $feature->payload;
                    $timestamp = $feature->microtimestamp;
                    $saved['feature_through'] = $timestamp;
                    $checkpoint->state['processed'] = ($checkpoint->state['processed'] ?? 0) + 1;
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
                        $saved['source_through'] = (int) $candles->key();
                        $candles->next();
                    }
                    while (count($window) <= $definition->horizon && $candles->valid()) {
                        $raw = $candles->current();
                        $raw['microtimestamp'] = (int) $candles->key();
                        $window[] = $raw;
                        $saved['source_through'] = $raw['microtimestamp'];
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
                $this->validateRevision($checkpoint, $exchange, $symbol, $period, $asOfMs);
                $checkpoint->save(false);
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
                DB::transaction(function () use ($checkpoint, $temporary, $directory, $json, $id, $manifest,
                    $exchange, $symbol, $period, $asOfMs, $actionDiagnostics): void {
                    $checkpoint->save(false);
                    $this->validateRevision($checkpoint, $exchange, $symbol, $period, $asOfMs, lock: true);
                    // A crash after rename but before DB commit may leave this build's unpublished directory.
                    if (is_dir($directory)) {
                        foreach (['rows.jsonl', 'manifest.json'] as $name) {
                            if (is_file($directory.'/'.$name)) {
                                unlink($directory.'/'.$name);
                            }
                        }
                        rmdir($directory);
                    }
                    if (file_put_contents($temporary.'/manifest.json', $json) !== strlen($json) || ! rename($temporary, $directory)) {
                        throw new RuntimeException('Could not publish dataset snapshot.');
                    }
                    // Renew parent leases before publishing. A later ownership loss
                    // must be handled outside this transaction, without rolling back
                    // a replacement token along with the dataset.
                    $checkpoint->onSave('snapshot-rows', null);
                    $checkpoint->state['stage'] = 'snapshot_complete';
                    $checkpoint->save(false);
                    $checkpoint->clearStream('snapshot-rows');
                    DB::table('research_datasets')->insert(['dataset_id' => $id, 'manifest' => json_encode($manifest, JSON_THROW_ON_ERROR), 'created_at' => now()]);
                    if ($actionDiagnostics !== null) {
                        $this->actionReports->publish($exchange, $symbol, $period, $actionDiagnostics, $id);
                    }
                });

                return $manifest;
            })();

            app(ActionLog::class)->write('dataset.saved', ['dataset_id' => $id,
                'exchange' => $exchange, 'symbol' => $symbol, 'period' => $period,
                'rows' => $manifest['rows'], 'outcome' => 'completed']);

            return $manifest;
        } catch (Throwable $error) {
            try {
                $checkpoint?->onSave('snapshot-rows', null);
                if (is_resource($file)) {
                    $closing = $file;
                    $file = null;
                    // Close before unlinking so NFS does not retain our open rows file.
                    fclose($closing);
                }
                if ($temporary !== null) {
                    foreach (['rows.jsonl', 'manifest.json'] as $name) {
                        if (is_file($temporary.'/'.$name)) {
                            unlink($temporary.'/'.$name);
                        }
                    }
                    if (is_dir($temporary)) {
                        rmdir($temporary);
                    }
                }
            } catch (Throwable $cleanupError) {
                try {
                    $log = app(ActionLog::class);
                    $log->write('dataset.cleanup.failed', [
                        ...$log->exception($cleanupError),
                        'dataset_id' => $checkpoint?->state['id'] ?? null,
                        'exchange' => $exchange, 'symbol' => $symbol, 'period' => $period,
                        'outcome' => 'failed',
                    ]);
                } catch (Throwable) {
                    // Cleanup and logging must not replace the original failure or BuildPaused.
                }
            }
            throw $error;
        } finally {
            if (is_resource($file)) {
                fclose($file);
            }
            $checkpoint?->close();
            if ($borrowedLock) {
                TrainingRuntime::unregisterLock($lock);
            } else {
                TrainingRuntime::unregisterLease('snapshot-features:'.$lock->owner());
                $lock->release();
            }
        }
    }

    private function validateRevision(BuildCheckpoint $checkpoint, string $exchange, string $symbol, string $period, int $asOfMs, bool $lock = false): void
    {
        $revision = $lock
            ? (int) (DB::table('market_history_backfills as history')
                ->join('markets', 'markets.market_id', '=', 'history.market_id')
                ->join('exchanges', 'exchanges.exchange_id', '=', 'markets.exchange_id')
                ->where('exchanges.class', $exchange)->where('markets.symbol', $symbol)->where('history.period', $period)
                ->lockForUpdate()->get(['history.history_revision'])->max('history_revision') ?? 0)
            : $this->labelSets->revision($exchange, $symbol, $period);
        if (! $this->labelSets->unchangedThrough($exchange, $symbol, $period, $asOfMs,
            $checkpoint->state['revision'], $revision)) {
            throw new RuntimeException('Candle history changed during snapshot generation; retry the build.');
        }
    }

    /** Compare actual eligible feature content across attempts, including insertions and deletions. */
    private function featureFingerprint(string $exchange, string $symbol, string $period, int $fromMs, int $toMs): string
    {
        $hash = hash_init('sha256');
        foreach (MarketFeature::query()->where(compact('exchange', 'symbol', 'period'))
            ->where('version', FeatureEngine::VERSION)->whereBetween('available_at_ms', [$fromMs, $toMs])
            ->lazyById(256, 'microtimestamp') as $row) {
            TrainingRuntime::heartbeat();
            hash_update($hash, BuildCheckpoint::encode([$row->getKey(), $row->microtimestamp, $row->available_at_ms, $row->payload])."\n");
        }

        return hash_final($hash);
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
