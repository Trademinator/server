<?php

namespace App\Domain\Intelligence;

use App\Domain\Features\FeatureEngine;
use App\Models\Market;
use App\Models\MarketFeature;
use App\Models\MarketSignal;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class SignalJournal
{
    public function __construct(private MarketIntelligence $intelligence) {}

    /**
     * Process at most a few new source candles per scheduled job, oldest first.
     * The first observation starts at the newest completed feature: we never
     * pretend to have made live decisions before the recorder was enabled.
     */
    public function recordPending(Market $market, int $limit = 6): void
    {
        $market->loadMissing('exchange', 'feed');
        $period = $market->feed?->selected_period;
        if ($period === null || ! $market->subscriptions()->where('active', true)->exists()) {
            return;
        }

        $query = MarketFeature::query()
            ->where('exchange', $market->exchange->class)->where('symbol', $market->symbol)
            ->where('period', $period)->where('version', FeatureEngine::VERSION)
            ->where('available_at_ms', '<=', now()->getTimestampMs());
        $cursor = MarketSignal::query()->where('market_id', $market->getKey())
            ->where('period', $period)->whereNotNull('decision_at_ms')->max('decision_at_ms');

        if ($cursor === null) {
            // No backfilled decisions for a market newly being followed/recorded.
            $latest = (clone $query)->max('available_at_ms');
            $this->record($market);

            return;
        }

        $pending = (clone $query)->where('available_at_ms', '>', (int) $cursor)
            ->orderBy('available_at_ms')->limit(max(1, min(12, $limit)))
            ->pluck('available_at_ms')->all();
        if ($pending === []) {
            // Recheck the current candle if the model/reason changes, but dedupe repeats.
            $this->record($market);

            return;
        }
        foreach ($pending as $closedAt) {
            if (! $market->subscriptions()->where('active', true)->exists()) {
                break;
            }
            $this->record($market, (int) $closedAt);
        }
    }

    public function record(Market $market, ?int $asOfMs = null): ?MarketSignal
    {
        $market->loadMissing('exchange', 'feed');
        $period = $market->feed?->selected_period;
        if ($period === null || ! $market->subscriptions()->where('active', true)->exists()) {
            return null;
        }
        $lock = Cache::lock('trademinator:signal:'.$market->market_id, 950);
        if (! $lock->get()) {
            return null;
        }
        try {
            try {
                $signal = $this->intelligence->predict($market->exchange->class, $market->symbol, $period, $asOfMs);
            } catch (Throwable $error) {
                report($error);
                $signal = [...WeightedKnn::abstain('model_unavailable'), 'patterns' => []];
            }
            // Even abstentions are tied to the specific closed candle under review.
            // A legacy no-feature observation retains its null source timestamp.
            $signal['decision_at_ms'] ??= $asOfMs;
            $signal['explanation'] = self::explain($signal['reason']);
            $signal['execution_status'] = 'unknown';
            if (! $market->subscriptions()->where('active', true)->exists()) {
                return null;
            }
            $previous = $market->latestSignal()->first();
            if (config('signal_memory.enabled')) {
                // Do not let a late job replace a newer observation or rewind t0.
                $previousAt = $previous !== null && $previous->period === $period
                    ? max($previous->decision_at_ms ?? 0,
                        (int) ($previous->payload['signal_memory_state']['last_processed_at_ms'] ?? 0)) : 0;
                if (isset($signal['decision_at_ms']) && $signal['decision_at_ms'] < $previousAt) {
                    return $previous;
                }
                // One atomic journal insert persists BOTH the verdict and its memory.
                // The existing shared-market lock serializes readers/writers here.
                $signal = app(SignalMemoryFilter::class)->apply($market, $period, $signal, $previous?->payload);
                $signal['explanation'] = self::explain($signal['reason']);
            }
            if ($previous !== null && $previous->period === $period && $previous->model_id === ($signal['model_id'] ?? null)
                && $previous->decision_at_ms === ($signal['decision_at_ms'] ?? null) && $previous->reason === $signal['reason']
                && $previous->action === $signal['action'] && ($previous->payload['regime'] ?? null) === ($signal['regime'] ?? null)
                && ($previous->payload['reference_price'] ?? null) === ($signal['reference_price'] ?? null)
                && ($previous->payload['reference_price_source'] ?? null) === ($signal['reference_price_source'] ?? null)
                && ($previous->payload['action_meaning'] ?? null) === ($signal['action_meaning'] ?? null)
                && ($previous->payload['scoring'] ?? null) == ($signal['scoring'] ?? null)) {
                return $previous;
            }
            // A recovery after an intervening state is a new observation, even for the same source candle.
            $key = hash('sha256', json_encode([$market->market_id, $period, $signal['model_id'] ?? null,
                $signal['decision_at_ms'] ?? null, $signal['reason'], $previous?->getKey()], JSON_THROW_ON_ERROR));
            $changed = $previous === null || $previous->period !== $period || $previous->model_id !== ($signal['model_id'] ?? null)
                || $previous->action !== $signal['action'] || $previous->reason !== $signal['reason']
                || ($previous->payload['regime'] ?? null) !== ($signal['regime'] ?? null);

            return MarketSignal::query()->firstOrCreate(['snapshot_key' => $key], [
                'market_id' => $market->market_id, 'period' => $period, 'model_id' => $signal['model_id'] ?? null,
                'decision_at_ms' => $signal['decision_at_ms'] ?? null, 'recorded_at_ms' => now()->getTimestampMs(),
                'is_change' => $changed, 'action' => $signal['action'], 'reason' => $signal['reason'], 'payload' => $signal,
            ]);
        } finally {
            $lock->release();
        }
    }

    /** A validated Action-only fallback still has a decision; it is not full Outcome confirmation. */
    public static function hasDecision(string $action, string $reason): bool
    {
        return $reason === 'supported'
            || ($reason === 'degraded_action_only' && in_array($action, ['buy', 'hodl', 'sell'], true));
    }

    public static function label(string $action, string $reason): string
    {
        if ($reason === 'awaiting_price_displacement') {
            return 'Waiting for price displacement';
        }
        if (! self::hasDecision($action, $reason)) {
            return 'Waiting for evidence';
        }

        $label = $action === 'hodl' ? 'HOLD' : strtoupper($action);

        return $reason === 'degraded_action_only' ? $label.' (Action-only)' : $label;
    }

    public static function explain(string $reason): string
    {
        return match ($reason) {
            'awaiting_price_displacement' => 'The Server proposal is supported, but directional price displacement has not exceeded the ATR(3, EMA) threshold.',
            'signal_memory_atr_unavailable' => 'Signal memory is waiting for positive, mature ATR(3, EMA) from contiguous completed candles.',
            'signal_memory_inputs_unavailable' => 'Signal memory cannot validate its candle inputs. The pending reference was reset; no action is released.',
            'signal_memory_source_mismatch' => 'Signal memory candles disagree with the proposal reference price. Rebuild the affected features before trading.',
            'signal_memory_candle_already_processed' => 'This source candle was already processed. Changed evidence cannot emit a second action for the same candle.',
            'supported' => 'Similar historical market states support this signal.',
            'degraded_action_only' => 'Only Action KNN has sufficient evidence for this candle; Outcome KNN cannot confirm it. Supported BUY/HOLD/SELL are retained with Action-only confidence.',
            'degraded_outcome_only' => 'Outcome KNN has evidence, but Action KNN cannot confirm a trade. The Server returns defensive HOLD with zero confidence.',
            'knn_abstention' => 'Neither Outcome nor Action KNN has enough supported evidence for a decision.',
            'no_model' => 'Training has not completed for this market yet.',
            'period_pending' => 'The collector is still selecting a reliable candle period.',
            'awaiting_recording' => 'A trained model is available, but no current recorded signal exists. Check the scheduled signal recorder and signal queue.',
            'no_eligible_k' => 'Historical evidence has not passed the semantic validation requirements.',
            'holdout_failed' => 'The selected model did not pass the separate later evaluation period.',
            'stale_model' => 'The trained model is too old. A fresh training run is needed.',
            'stale_features' => 'Recent closed-candle features are not available.',
            'no_post_training_candle' => 'Waiting for a closed candle after the training cutoff.',
            'model_not_published_at_close' => 'The currently published model was not yet available when this candle closed; no retrospective trading decision was fabricated.',
            'missing_features', 'missing_selected_features' => 'The model needs more complete feature history.',
            'source_feature_mismatch' => 'Stored candles and features do not match. Features need rebuilding.',
            'model_version_mismatch' => 'The model needs rebuilding with the current intelligence version.',
            'no_similar_history' => 'No historical examples are close enough to the current market state.',
            'insufficient_effective_neighbors' => 'Too few effectively weighted examples support a decision.',
            'tied_votes' => 'The strongest historical votes are tied.',
            'tied_model_scores' => 'The combined model scores are tied.',
            'weak_model_consensus' => 'The combined model scores do not agree strongly enough.',
            'no_supported_models', 'no_weighted_model' => 'No enabled model has sufficient evidence for scoring.',
            'weak_consensus' => 'The historical examples do not agree strongly enough.',
            default => 'The model is currently unavailable. No directional signal is being issued.',
        };
    }
}
