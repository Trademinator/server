<?php

namespace App\Domain\Intelligence;

use App\Domain\Features\ContextFeatures;
use App\Domain\Features\FeatureEngine;
use App\Domain\MarketData\CandleTimeframe;
use App\Domain\Research\FeatureSchema;
use App\Jobs\TrainMarketIntelligence;
use App\Models\CoinGeckoMarketMapping;
use App\Models\MarketFeature;
use App\Models\MarketFeed;
use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class IntelligenceReadiness
{
    private const HISTORY_SAMPLE_SIZE = 2000;

    public function __construct(private CandleTimeframe $timeframe) {}

    public function describe(string $exchange, string $symbol, ?string $period, ?MarketFeed $feed, ?array $report, array $signal): array
    {
        $settings = $report['settings'] ?? config('intelligence.knn');
        $outcomeSettings = $settings['outcome'] ?? config('intelligence.outcome');
        $horizon = (int) ($report['label_definition']['horizon'] ?? config('intelligence.horizon'));
        $minimum = TrainingRequirements::minimumRows($settings, $horizon);
        $data = [
            'minimum' => $minimum, 'full_fold_minimum' => TrainingRequirements::minimumRows($settings, $horizon, true),
            'settings' => $settings, 'horizon' => $horizon, 'history' => null,
            'eta' => null, 'eta_note' => 'ETA unavailable until a candle period and recent complete features are available.',
            'issues' => [], 'tuning' => null, 'holdout' => null, 'next_training' => $this->nextTraining(),
            'queue' => config('intelligence.queue'), 'source' => $this->source($report), 'schema' => null, 'full_schema' => null,
            'evidence_evaluated' => in_array($signal['reason'], ['supported', 'degraded_action_only', 'no_similar_history',
                'insufficient_effective_neighbors', 'tied_votes', 'weak_consensus', 'tied_model_scores', 'weak_model_consensus'], true),
            'action' => $this->action($signal['reason']),
            'pattern_minimum' => $report['pattern_settings']['min_samples'] ?? config('intelligence.patterns.min_samples'),
        ];
        $data['schema'] = $data['source']['schema'] ?? (string) config('intelligence.schema');
        if (! config('intelligence.enabled')) {
            $data['issues'][] = 'Automatic intelligence training is disabled (INTELLIGENCE_ENABLED).';
        }
        if (in_array(config('queue.default'), ['sync', 'null'], true)) {
            $data['issues'][] = 'Automatic training requires a persistent queue connection; the current connection cannot dispatch intelligence jobs.';
        }
        if ($feed !== null && ! in_array($feed->status, ['ready', 'active', 'queued'], true)) {
            $data['issues'][] = 'Collection status: '.$feed->status.'. Check the market collector before relying on an ETA.';
        }
        if ($feed?->last_error) {
            $data['issues'][] = 'The collector reported an error. Check collection logs and exchange connectivity.';
        }
        if ($report !== null) {
            $candidates = $report['selection']['candidates'] ?? [];
            // Action accounting also has a `supported` count. Only the
            // five-class Outcome report has macro-F1 and its Outcome gates.
            $gatesFor = fn (array $metrics): array => array_key_exists('macro_f1', $metrics)
                ? TrainingRequirements::outcomeGates($metrics, $settings, $outcomeSettings)
                : TrainingRequirements::gates($metrics, $settings);
            usort($candidates, function (array $a, array $b) use ($gatesFor): int {
                $passed = fn (array $candidate): int => count(array_filter($gatesFor($candidate), fn (array $gate): bool => $gate['passed']));

                return [$passed($b), $b['semantic_precision'], -$b['k']] <=> [$passed($a), $a['semantic_precision'], -$a['k']];
            });
            $selected = array_values(array_filter($candidates, fn (array $candidate): bool => $candidate['k'] === $report['k']));
            $candidate = $selected[0] ?? $candidates[0] ?? null;
            if ($candidate !== null) {
                $data['tuning'] = ['k' => $candidate['k'], 'gates' => $gatesFor($candidate)];
            }
            if ($report['holdout'] ?? null) {
                $data['holdout'] = $gatesFor($report['holdout']);
            }
        }
        if ($period === null) {
            return $data;
        }
        $keys = $report['keys'] ?? FeatureSchema::keys(config('intelligence.schema'));
        $latest = MarketFeature::query()->where('exchange', $exchange)->where('symbol', $symbol)->where('period', $period)
            ->where('version', FeatureEngine::VERSION)->where('available_at_ms', '<=', now()->getTimestampMs())
            ->orderByDesc('microtimestamp')->first(['feature_id', 'microtimestamp', 'available_at_ms', 'payload']);
        $history = $this->history($exchange, $symbol, $period, $keys, $horizon, $latest);
        $data['history'] = $history;
        $data['full_schema'] = $this->fullSchema($exchange, $symbol, $latest);
        if ($history['closed'] === 0) {
            $data['issues'][] = 'No current-version closed-candle features exist for this market and period. Run the M2 feature builder.';
        } elseif ($history['stale']) {
            $data['issues'][] = 'Feature collection is stale. Check the collector, scheduler and default queue worker.';
        }
        if ($history['missing_keys'] !== []) {
            $data['issues'][] = 'Latest candle is missing selected features: '.implode(', ', $history['missing_keys']).'.';
        }
        if ($history['invalid']) {
            $data['issues'][] = 'Some selected feature values are invalid. Rebuild the M2 features.';
        }
        if ($history['gaps'] > 0) {
            $data['issues'][] = 'The feature window contains gaps. The training builder must verify continuous source candles before these rows can be used.';
        }
        $skipped = $data['source']['skipped'] ?? [];
        if (($skipped['gaps'] ?? 0) + ($skipped['missing_source'] ?? 0) > 0) {
            $data['issues'][] = 'The last training dataset excluded rows with missing or nonconsecutive source candles. Repair or backfill the source history.';
        }
        $remaining = max(0, $minimum - $history['potential']);
        if ($data['issues'] !== [] || $history['closed'] < 2 || $history['complete'] === 0) {
            $data['eta_note'] = 'ETA unavailable while collection, feature completeness or configuration needs attention.';
        } elseif (($report['pattern_keys'] ?? []) !== [] || ($report['lead_lag_keys'] ?? []) !== []) {
            $data['eta_note'] = 'ETA unavailable: retraining the pattern or lead/lag models changes how much earlier history must be excluded from KNN training.';
        } elseif ($remaining === 0) {
            $data['eta_note'] = 'Enough potential history is present to attempt training now. Source checks, pattern exclusions and validation still have to pass.';
        } elseif ($history['sampled']) {
            $data['eta_note'] = 'ETA unavailable: only recent feature rows were checked. Older stored rows may already satisfy the history requirement; training checks all available eligible history.';
        } else {
            $eta = $history['latest_ms'];
            for ($i = 0; $i < $remaining; $i++) {
                $eta = $this->timeframe->next($eta, $period);
            }
            $data['eta'] = CarbonImmutable::createFromTimestampMs(max($eta, now()->getTimestampMs()))->utc();
            $data['eta_note'] = 'Live-collection estimate only, at one usable row per '.$period.' candle. Existing historical candles can be backfilled and used immediately; there is no requirement to wait for this date. Source checks, feature completeness and validation still apply.';
        }

        return $data;
    }

    /** Bounded display estimate only. Training uses every eligible row in the available history. */
    private function history(string $exchange, string $symbol, string $period, array $keys, int $horizon, ?MarketFeature $latest): array
    {
        $now = now()->getTimestampMs();
        $asOfMs = $latest?->microtimestamp ?? $now;
        $result = ['closed' => 0, 'complete' => 0, 'potential' => 0, 'immature' => 0, 'as_of_ms' => $asOfMs,
            'checked' => 0, 'sampled' => false,
            'missing_keys' => [], 'invalid' => false, 'gaps' => 0, 'latest_ms' => $latest?->available_at_ms, 'stale' => true];
        if ($latest === null) {
            return $result;
        }
        $staleAt = $latest->available_at_ms;
        for ($i = 0; $i < config('intelligence.max_signal_age_periods'); $i++) {
            $staleAt = $this->timeframe->next($staleAt, $period);
        }
        $result['stale'] = $now >= $staleAt;
        $latestPayload = $latest->payload;
        $result['missing_keys'] = array_values(array_filter($keys, fn (string $key): bool => ($latestPayload['features'][$key] ?? null) === null));
        $query = MarketFeature::query()->where('exchange', $exchange)->where('symbol', $symbol)->where('period', $period)
            ->where('version', FeatureEngine::VERSION)->where('microtimestamp', '<=', $asOfMs)
            ->whereBetween('available_at_ms', [KnowledgeWindow::trainingFromMs(), $now]);
        $result['closed'] = (clone $query)->count();
        $features = $query->orderByDesc('available_at_ms')->orderByDesc('microtimestamp')
            ->limit(self::HISTORY_SAMPLE_SIZE)->get(['feature_id', 'microtimestamp', 'available_at_ms', 'payload'])
            ->sortBy('microtimestamp');
        $result['checked'] = $features->count();
        $result['sampled'] = $result['checked'] < $result['closed'];
        $previous = null;
        foreach ($features as $feature) {
            if ($previous !== null && $this->timeframe->next($previous, $period) !== $feature->microtimestamp) {
                $result['gaps']++;
            }
            $previous = $feature->microtimestamp;
            $payload = $feature->payload;
            if (($payload['version'] ?? null) !== FeatureEngine::VERSION
                || ($payload['microtimestamp'] ?? null) !== $feature->microtimestamp
                || ($payload['available_at_ms'] ?? null) !== $feature->available_at_ms
                || $feature->available_at_ms !== $this->timeframe->next($feature->microtimestamp, $period)) {
                $result['invalid'] = true;

                continue;
            }
            try {
                if (FeatureSchema::vector($payload, $keys) === null) {
                    continue;
                }
            } catch (InvalidArgumentException) {
                $result['invalid'] = true;

                continue;
            }
            $result['complete']++;
            // Match the default build's cutoff: reserve the latest candle for inference.
            $available = $feature->available_at_ms;
            for ($i = 0; $i < $horizon; $i++) {
                $available = $this->timeframe->next($available, $period);
            }
            if ($available <= $latest->microtimestamp) {
                $result['potential']++;
            } else {
                $result['immature']++;
            }
        }

        return $result;
    }

    private function fullSchema(string $exchange, string $symbol, ?MarketFeature $latest): array
    {
        $contextKeys = ContextFeatures::KEYS;
        $technicalKeys = FeatureSchema::keys('technical');
        $mapping = CoinGeckoMarketMapping::query()
            ->whereHas('market', fn ($market) => $market->where('symbol', $symbol)
                ->whereHas('exchange', fn ($query) => $query->where('class', $exchange)))
            ->first();
        $payload = $latest?->payload;
        $features = $payload['features'] ?? [];
        $contextMissing = array_values(array_filter($contextKeys, fn (string $key): bool => ($features[$key] ?? null) === null));
        $technicalMissing = array_values(array_filter($technicalKeys, fn (string $key): bool => ($features[$key] ?? null) === null));
        $technicalReady = false;
        $fullReady = false;
        $invalid = false;
        if ($latest !== null) {
            try {
                $technicalReady = FeatureSchema::vector($payload, $technicalKeys) !== null;
                $fullReady = $technicalReady;
            } catch (InvalidArgumentException) {
                $invalid = true;
            }
        }

        return [
            'context_available' => count($contextKeys) - count($contextMissing),
            'context_total' => count($contextKeys),
            'missing' => $contextMissing,
            'technical_missing' => $technicalMissing,
            'technical_ready' => $technicalReady,
            'full_ready' => $fullReady,
            'invalid' => $invalid,
            'mapping' => [
                'status' => $mapping?->status ?? 'missing',
                'coin_id' => $mapping?->coin_id,
                'coin_name' => $mapping?->coin_name,
                'vs_currency' => $mapping?->vs_currency,
                'error' => $mapping?->last_error,
            ],
        ];
    }

    private function source(?array $report): ?array
    {
        if ($report === null) {
            return null;
        }
        if (isset($report['training_data'])) {
            return $report['training_data'];
        }
        $json = DB::table('research_datasets')->where('dataset_id', $report['dataset_id'])->value('manifest');
        $manifest = $json === null ? [] : json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return ['source_rows' => $manifest['rows'] ?? null,
            'usable_rows' => ($report['pattern_keys'] ?? []) === [] ? ($manifest['rows'] ?? null) : null,
            'pattern_excluded_rows' => null, 'skipped' => $manifest['skipped'] ?? [],
            'schema' => $manifest['schema'] ?? 'unknown'];
    }

    private function nextTraining(): ?CarbonImmutable
    {
        if (! config('intelligence.enabled') || in_array(config('queue.default'), ['sync', 'null'], true)) {
            return null;
        }
        $timezone = config('app.schedule_timezone', config('app.timezone'));
        $next = (new CronExpression(TrainMarketIntelligence::CRON))->getNextRunDate(now()->toDateTimeImmutable(), timeZone: $timezone);

        return CarbonImmutable::instance($next)->utc();
    }

    private function action(string $reason): string
    {
        return match ($reason) {
            'period_pending' => 'Let the collector select a reliable period; check collection if it remains pending.',
            'no_model' => 'Run the M4 knowledge build after M2 features exist, or dispatch training and drain the intelligence queue. M2/M3 jobs alone do not train an M4 model.',
            'no_eligible_k', 'holdout_failed' => 'Review the failed validation checks below. Once history improves, rebuild the model; existing models do not absorb new rows automatically.',
            'insufficient_directional_evidence' => 'There are too few finalized BUY/SELL opportunities or supported directional predictions for a reliable Action validation. Collect more history before rebuilding.',
            'stale_model', 'model_version_mismatch' => 'Rebuild the model from current features.',
            'missing_features', 'stale_features', 'missing_selected_features', 'source_feature_mismatch' => 'Check collection and rebuild the selected M2 features. Then rebuild the model if needed.',
            'no_post_training_candle' => 'Wait for the next genuinely closed candle and its feature build.',
            'awaiting_recording' => 'A validated model is available. Wait for the next signal recording and intelligence queue run; an older observation is not a current signal.',
            'degraded_action_only' => 'Only Action KNN has sufficient inference evidence. Its supported BUY/HOLD/SELL suggestion is retained without Outcome confirmation; review Outcome validation separately.',
            'degraded_outcome_only', 'knn_abstention' => 'No supported Action KNN decision is available. Inspect the individual Outcome and Action evidence below.',
            'no_similar_history', 'insufficient_effective_neighbors', 'tied_votes', 'weak_consensus',
            'tied_model_scores', 'weak_model_consensus' => 'The model is available but this market state lacks sufficient evidence. More time does not guarantee a directional signal.',
            'supported' => 'The model passed validation and evaluated the current candle. HOLD can also be a supported model decision.',
            default => 'Check the intelligence worker logs, failed jobs and shared model storage, then rebuild the model.',
        };
    }
}
