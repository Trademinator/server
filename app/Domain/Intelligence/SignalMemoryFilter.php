<?php

namespace App\Domain\Intelligence;

use App\Helpers\Decimal;
use App\Models\Market;
use Throwable;

/** Journal-only filter. Inference, training and read-only pages never advance it. */
final class SignalMemoryFilter
{
    public function __construct(private SignalMemoryAtr $atr, private SignalMemoryPolicy $policy) {}

    public function apply(Market $market, string $period, array $proposal, ?array $previous): array
    {
        $raw = $proposal;
        unset($raw['explanation'], $raw['execution_status']);
        $fingerprint = hash('sha256', json_encode($raw, JSON_THROW_ON_ERROR));
        $old = $previous['signal_memory_state'] ?? [];
        $sameScope = ($old['version'] ?? null) === SignalMemoryPolicy::VERSION
            && ($old['market_id'] ?? null) === $market->getKey()
            && ($old['period'] ?? null) === $period;
        if ($sameScope && ($old['raw_fingerprint'] ?? null) === $fingerprint) {
            // Replay the stored verdict, NOT the transition from its post-action t0.
            return $previous;
        }
        $state = $sameScope ? $old : [
            'version' => SignalMemoryPolicy::VERSION, 'market_id' => $market->getKey(),
            'period' => $period, 'model_id' => null, 'last_processed_at_ms' => null,
            'anchor' => null, 'checkpoint' => null,
        ];
        if (($state['model_id'] ?? null) !== ($proposal['model_id'] ?? null)) {
            $state['anchor'] = null;
        }
        $state['model_id'] = $proposal['model_id'] ?? null;
        $state['raw_fingerprint'] = $fingerprint;
        $at = $proposal['decision_at_ms'] ?? null;
        $watermark = $state['last_processed_at_ms'];
        // Activation must not re-emit a candle already recorded without this policy.
        if (! $sameScope && (! isset($old['market_id']) || $old['market_id'] === $market->getKey())
            && ($previous['period'] ?? null) === $period
            && is_int($previous['decision_at_ms'] ?? null)) {
            $watermark = $previous['decision_at_ms'];
        }
        $validTime = is_int($at) && $at > 0 && $at <= now()->getTimestampMs();
        if ($validTime) {
            $state['last_processed_at_ms'] = max($at, $watermark ?? 0);
        }
        $side = $proposal['action'] ?? 'hodl';
        $reason = $proposal['reason'] ?? 'model_unavailable';
        $meta = [
            'version' => SignalMemoryPolicy::VERSION, 'status' => 'abstaining',
            'raw_action' => $side, 'raw_reason' => $reason,
            'raw_confidence' => $proposal['confidence'] ?? 0,
            'raw_action_meaning' => $proposal['action_meaning'] ?? null,
            'path' => 'upstream_abstention', 'immediate_delta' => null,
            'accumulated_delta' => null, 'applied_threshold' => null,
        ];
        if (! SignalJournal::hasDecision($side, $reason)) {
            $state['anchor'] = null;

            return $this->attach($proposal, $state, $meta);
        }
        if (! $validTime) {
            $state['anchor'] = null;
            $state['checkpoint'] = null;

            return $this->wait($proposal, $state, $meta, 'signal_memory_inputs_unavailable');
        }
        if (in_array($side, ['buy', 'sell'], true)
            && ($state['anchor']['direction'] ?? null) !== $side) {
            // Cancel the opposite candidate even when the new side will have to wait.
            $state['anchor'] = null;
        }
        if ($watermark !== null && $at <= $watermark) {
            if ($side === 'hodl') {
                $meta['status'] = 'hold';
                $meta['path'] = 'upstream_hold';

                return $this->attach($proposal, $state, $meta);
            }

            return $this->wait($proposal, $state, $meta, 'signal_memory_candle_already_processed');
        }
        try {
            $context = $this->atr->context($market->exchange->class, $market->symbol,
                $period, $at, $state['checkpoint']);
        } catch (Throwable $error) {
            report($error);
            $context = ['error' => 'signal_memory_inputs_unavailable'];
        }
        $state['checkpoint'] = $context['checkpoint'] ?? null;
        if (isset($context['error'])) {
            $state['anchor'] = null;

            return $this->wait($proposal, $state, $meta, $context['error']);
        }
        if ($context['reset'] ?? false) {
            $state['anchor'] = null;
        }
        $meta['atr_current'] = $context['atr'];
        $meta['atr_previous'] = $context['previous_atr'];
        if ($side === 'hodl') {
            // HOLD updates causal ATR continuity, but never releases a pending action.
            $meta['status'] = 'hold';
            $meta['path'] = 'upstream_hold';

            return $this->attach($proposal, $state, $meta);
        }
        try {
            $reference = Decimal::normalize($proposal['reference_price'] ?? '0');
            if (SignalMemoryPolicy::greater($reference, $context['close'])
                || SignalMemoryPolicy::greater($context['close'], $reference)) {
                $state['anchor'] = null;

                return $this->wait($proposal, $state, $meta, 'signal_memory_source_mismatch');
            }
            $decision = $this->policy->decide($side, $at, $context['close'], $context['previous_close'],
                $context['atr'], $context['previous_atr'], $state['anchor']);
        } catch (Throwable $error) {
            report($error);
            $state['anchor'] = null;

            return $this->wait($proposal, $state, $meta, 'signal_memory_inputs_unavailable');
        }
        $state['anchor'] = $decision['anchor'];
        $meta = [...$meta, 'status' => $decision['emit'] ? 'emitted' : 'pending',
            'path' => $decision['path'], 'immediate_delta' => $decision['immediate'],
            'accumulated_delta' => $decision['accumulated'], 'applied_threshold' => $decision['threshold']];
        if (! $decision['emit']) {
            return $this->wait($proposal, $state, $meta, 'awaiting_price_displacement');
        }

        return $this->attach($proposal, $state, $meta);
    }

    private function wait(array $proposal, array $state, array $meta, string $reason): array
    {
        if ($reason !== 'awaiting_price_displacement') {
            $meta['status'] = 'waiting';
            $meta['path'] = $reason;
        }
        $proposal['action'] = 'hodl';
        $proposal['reason'] = $reason;
        $proposal['confidence'] = 0.0;
        $proposal['action_meaning'] = null;

        return $this->attach($proposal, $state, $meta);
    }

    private function attach(array $proposal, array $state, array $meta): array
    {
        $meta['anchor'] = $state['anchor'];
        $meta['raw_resolved_action'] = $proposal['scoring']['resolved_action']
            ?? ($meta['raw_action'] === 'hodl' ? 'hold' : $meta['raw_action']);
        $meta['resolved_action'] = $proposal['action'] === 'hodl' ? 'hold' : $proposal['action'];
        // Keep the public resolved result consistent with the final Server action.
        // Original KNN components and upstream resolution remain available for audit.
        $proposal['scoring']['resolved_action'] = $meta['resolved_action'];
        // Client API already exposes scoring; recursive ATR checkpoint stays private.
        $proposal['scoring']['signal_memory'] = $meta;
        $proposal['signal_memory_state'] = $state;

        return $proposal;
    }
}
