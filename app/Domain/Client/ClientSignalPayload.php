<?php

namespace App\Domain\Client;

use App\Domain\Intelligence\ModelStore;
use App\Domain\Intelligence\SignalFreshness;
use App\Domain\Intelligence\SignalSemantics;
use App\Models\MarketSubscription;
use Illuminate\Support\Facades\DB;

/**
 * Read-only Server intelligence for the Client.
 * No wallet, position, execution-eligibility, or order-sizing state is accepted.
 */
final class ClientSignalPayload
{
    public function __construct(private SignalFreshness $freshness) {}

    public function for(MarketSubscription $subscription): ?array
    {
        $subscription->loadMissing('market.exchange', 'market.feed', 'market.latestSignal');
        $market = $subscription->market;
        $signal = $market->latestSignal;
        if ($signal === null) {
            return null;
        }

        $selectedPeriod = $market->feed?->selected_period;
        $expiresAt = $this->freshness->expiresAt($signal->decision_at_ms, $signal->period);
        $samePeriod = $selectedPeriod !== null && $selectedPeriod === $signal->period;
        $headModelId = $samePeriod
            ? DB::table('intelligence_heads')
                ->where('market_key', ModelStore::marketKey($market->exchange->class, $market->symbol, $selectedPeriod))
                ->value('model_id')
            : null;

        // Freshness and model provenance describe the Server evidence, not a trade authorization.
        // The Client must still check its own clock, balances, orders, fees and risk rules.
        $isFresh = $samePeriod && $expiresAt !== null && now()->getTimestampMs() < $expiresAt;
        $isCurrentModel = $signal->model_id !== null && $headModelId !== null && $signal->model_id === $headModelId;

        return [
            'id' => $signal->getKey(),
            'action' => SignalSemantics::clientAction($signal->action, $signal->reason),
            'action_meaning' => $signal->payload['action_meaning']
                ?? SignalSemantics::actionMeaning($signal->action, $signal->reason, $signal->payload['scoring'] ?? null),
            'reason' => $signal->reason,
            'evidence_status' => SignalSemantics::evidenceStatus($signal->reason),
            'period' => $signal->period,
            'model_id' => $signal->model_id,
            'is_current_model' => $isCurrentModel,
            'is_fresh' => $isFresh,
            'expires_at_ms' => $expiresAt,
            'confidence' => in_array($signal->reason, ['supported', 'degraded_action_only'], true)
                ? (float) ($signal->payload['confidence'] ?? 0.0) : null,
            'recorded_at_ms' => $signal->recorded_at_ms,
            'decision_at_ms' => $signal->decision_at_ms,
            'reference_price' => $signal->payload['reference_price'] ?? null,
            'reference_price_source' => $signal->payload['reference_price_source'] ?? null,
            'prediction_input_basis' => $signal->payload['prediction_input_basis'] ?? null,
            'scoring' => $signal->payload['scoring'] ?? null,
        ];
    }
}
