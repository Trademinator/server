<?php

namespace App\Domain\Client;

use App\Domain\Intelligence\ModelStore;
use App\Domain\Intelligence\SignalFreshness;
use App\Domain\Intelligence\SignalSemantics;
use App\Helpers\Decimal;
use App\Models\ClientMarketSetting;
use App\Models\MarketSignal;
use App\Models\MarketSubscription;
use Illuminate\Support\Facades\DB;

final class ClientDecisionService
{
    private const SCALE = 18;

    public function __construct(private SignalFreshness $freshness) {}

    public function evaluate(MarketSubscription $subscription, array $state, string $mode = 'live'): array
    {
        $subscription->loadMissing('market.exchange', 'market.feed', 'market.latestSignal', 'clientSetting');
        $now = now()->getTimestampMs();
        $ttl = (int) config('client.response_ttl_seconds');
        $setting = $subscription->clientSetting;
        $signal = $subscription->market->latestSignal;
        $expires = $now + $ttl * 1000;

        $base = [
            'api_version' => 1,
            'subscription_id' => $subscription->getKey(),
            'active_subscription' => (bool) $subscription->active,
            'mode' => $mode,
            'checked_at_ms' => $now,
            'expires_at_ms' => $expires,
            'server_signal' => $this->signalPayload($signal),
            'eligible' => false,
            'reason' => 'unavailable',
            'action' => null,
            'action_meaning' => null,
            'sizing' => null,
        ];

        if (! $subscription->active) {
            return [...$base, 'reason' => 'subscription_inactive'];
        }
        if ($setting === null) {
            return [...$base, 'reason' => 'client_settings_missing'];
        }
        if ($subscription->market->feed?->selected_period === null) {
            return [...$base, 'reason' => 'period_pending'];
        }
        if ($signal === null) {
            return [...$base, 'reason' => 'no_recorded_signal'];
        }

        $signalExpiry = $this->freshness->expiresAt($signal->decision_at_ms, $signal->period);
        $base['expires_at_ms'] = min($expires, $signalExpiry ?? $expires);
        if (! in_array($signal->reason, ['supported', 'degraded_action_only'], true)) {
            // No actionable signal is still an explicit HOLD to the Client.
            // The original diagnostic reason remains in server_signal.reason.
            return [...$base, 'reason' => 'server_abstention', 'action' => 'hold'];
        }
        if ($signalExpiry === null || $now >= $signalExpiry) {
            return [...$base, 'reason' => 'stale_signal'];
        }
        if (! $this->isCurrentModel($subscription, $signal)) {
            return [...$base, 'reason' => 'signal_model_superseded'];
        }
        if ($signal->action === 'hodl') {
            return [...$base, 'reason' => 'server_hold', 'action' => 'hold'];
        }
        if (! in_array($signal->action, ['buy', 'sell'], true)) {
            return [...$base, 'reason' => 'unsupported_signal_action'];
        }
        $base['action_meaning'] = $signal->payload['action_meaning']
            ?? SignalSemantics::actionMeaning($signal->action, $signal->reason, $signal->payload['scoring'] ?? null);

        $confidence = (float) ($signal->payload['confidence'] ?? 0.0);
        if ($confidence + 1e-12 < (float) $setting->min_signal_confidence) {
            return [...$base, 'reason' => 'confidence_below_client_minimum', 'action' => $signal->action];
        }

        $reportedAt = (int) ($state['reported_at_ms'] ?? 0);
        $maxAge = (int) config('client.state_max_age_seconds') * 1000;
        if ($reportedAt <= 0 || $reportedAt > $now + 5000 || $reportedAt < $now - $maxAge) {
            return [...$base, 'reason' => 'stale_client_state', 'action' => $signal->action];
        }
        if (($setting->block_conflicting_exposure ?? true) && ($state['conflicting_exposure'] ?? false)) {
            return [...$base, 'reason' => 'conflicting_exposure', 'action' => $signal->action];
        }

        $bid = $this->d($state['best_bid']);
        $ask = $this->d($state['best_ask']);
        if (bccomp($bid, '0', self::SCALE) <= 0 || bccomp($ask, $bid, self::SCALE) < 0) {
            return [...$base, 'reason' => 'invalid_order_book', 'action' => $signal->action];
        }
        $mid = bcdiv(bcadd($bid, $ask, self::SCALE), '2', self::SCALE);
        $spreadBps = bcmul(bcdiv(bcsub($ask, $bid, self::SCALE), $mid, self::SCALE), '10000', 8);
        if (bccomp($spreadBps, $this->d($setting->max_spread_bps), 8) > 0) {
            return [...$base, 'reason' => 'spread_too_wide', 'action' => $signal->action,
                'risk' => ['spread_bps' => (float) $spreadBps]];
        }

        $referenceRaw = $signal->payload['reference_price'] ?? null;
        if (! is_numeric($referenceRaw) || ! is_finite((float) $referenceRaw) || (float) $referenceRaw <= 0) {
            return [...$base, 'reason' => 'signal_reference_price_missing', 'action' => $signal->action];
        }
        $referencePrice = $this->d($referenceRaw);
        $executionReference = $signal->action === 'buy' ? $ask : $bid;
        $difference = bcsub($executionReference, $referencePrice, self::SCALE);
        if (bccomp($difference, '0', self::SCALE) < 0) {
            $difference = bcmul($difference, '-1', self::SCALE);
        }
        $signalDriftBps = bcmul(bcdiv($difference, $referencePrice, self::SCALE), '10000', 8);
        $maxSignalDriftBps = $this->d($setting->max_signal_drift_bps ?? config('client.default_max_signal_drift_bps'));
        $marketRisk = ['spread_bps' => (float) $spreadBps, 'signal_drift_bps' => (float) $signalDriftBps,
            'signal_reference_price' => (float) $referencePrice, 'execution_reference_price' => (float) $executionReference];
        if (bccomp($signalDriftBps, $maxSignalDriftBps, 8) > 0) {
            return [...$base, 'reason' => 'signal_price_drift', 'action' => $signal->action, 'risk' => $marketRisk];
        }

        $feeBps = $this->d($state['taker_fee_bps']);
        if (bccomp($feeBps, $this->d($setting->max_taker_fee_bps), 8) > 0) {
            return [...$base, 'reason' => 'fee_too_high', 'action' => $signal->action];
        }

        $sizing = $signal->action === 'buy'
            ? $this->buySize($setting, $state, $ask, $feeBps)
            : $this->sellSize($setting, $state, $bid);
        if (! $sizing['eligible']) {
            return [...$base, 'reason' => $sizing['reason'], 'action' => $signal->action, 'risk' => $marketRisk];
        }

        unset($sizing['eligible'], $sizing['reason']);

        return [...$base, 'eligible' => true, 'reason' => 'eligible', 'action' => $signal->action,
            'sizing' => $sizing, 'risk' => [...$marketRisk, 'taker_fee_bps' => (float) $feeBps]];
    }

    private function buySize(ClientMarketSetting $setting, array $state, string $ask, string $feeBps): array
    {
        $maxOrder = $this->d($setting->max_order_quote);
        if (bccomp($maxOrder, '0', self::SCALE) <= 0) {
            return ['eligible' => false, 'reason' => 'max_order_quote_not_configured'];
        }
        $quoteBalance = $this->d($state['quote_balance']);
        $reserve = $this->d($setting->reserve_quote);
        $available = bcsub($quoteBalance, $reserve, self::SCALE);
        if (bccomp($available, '0', self::SCALE) <= 0) {
            return ['eligible' => false, 'reason' => 'quote_reserve_required'];
        }
        $feeRate = bcdiv($feeBps, '10000', self::SCALE);
        $feeFromQuote = ($state['fee_asset'] ?? 'quote') === 'quote';
        $availableSpend = $feeFromQuote
            ? bcdiv($available, bcadd('1', $feeRate, self::SCALE), self::SCALE)
            : $available;
        $spend = $this->minDecimal($maxOrder, $availableSpend);
        if (isset($state['requested_quote'])) {
            $spend = $this->minDecimal($spend, $this->d($state['requested_quote']));
        }
        if ($setting->max_position_quote !== null) {
            $remaining = bcsub($this->d($setting->max_position_quote), $this->d($state['position_quote'] ?? '0'), self::SCALE);
            $spend = $this->minDecimal($spend, bccomp($remaining, '0', self::SCALE) > 0 ? $remaining : '0');
        }
        if (bccomp($spend, '0', self::SCALE) <= 0) {
            return ['eligible' => false, 'reason' => 'position_or_balance_limit'];
        }

        $amount = bcdiv($spend, $ask, self::SCALE);
        $amount = $this->applyStep($amount, $state['amount_step'] ?? null);
        $spend = bcmul($amount, $ask, self::SCALE);
        $fee = $feeFromQuote ? bcmul($spend, $feeRate, self::SCALE) : '0';
        $constraint = $this->constraints($amount, $spend, $state);
        if ($constraint !== null) {
            return ['eligible' => false, 'reason' => $constraint];
        }

        return ['eligible' => true, 'reason' => 'eligible', 'base_amount' => $amount,
            'quote_amount' => $spend, 'estimated_fee_quote' => $fee, 'reference_price' => $ask];
    }

    private function sellSize(ClientMarketSetting $setting, array $state, string $bid): array
    {
        $baseBalance = $this->d($state['base_balance']);
        if (bccomp($baseBalance, '0', self::SCALE) <= 0) {
            return ['eligible' => false, 'reason' => 'no_base_balance'];
        }
        $managedBase = array_key_exists('managed_base_balance', $state) && $state['managed_base_balance'] !== null
            ? $this->d($state['managed_base_balance'])
            : null;
        if ($managedBase !== null && bccomp($managedBase, '0', self::SCALE) <= 0) {
            return ['eligible' => false, 'reason' => 'managed_base_unavailable'];
        }
        $availableBase = $managedBase === null
            ? $baseBalance
            : $this->minDecimal($baseBalance, $managedBase);
        $maxOrder = $this->d($setting->max_order_quote);
        if (bccomp($maxOrder, '0', self::SCALE) <= 0) {
            return ['eligible' => false, 'reason' => 'max_order_quote_not_configured'];
        }
        $amount = $this->minDecimal($availableBase, bcdiv($maxOrder, $bid, self::SCALE));
        if (isset($state['requested_quote'])) {
            $amount = $this->minDecimal($amount, bcdiv($this->d($state['requested_quote']), $bid, self::SCALE));
        }
        $amount = $this->applyStep($amount, $state['amount_step'] ?? null);
        $quote = bcmul($amount, $bid, self::SCALE);
        $constraint = $this->constraints($amount, $quote, $state);
        if ($constraint !== null) {
            return ['eligible' => false, 'reason' => $constraint];
        }

        return ['eligible' => true, 'reason' => 'eligible', 'base_amount' => $amount,
            'quote_amount' => $quote, 'estimated_fee_quote' => '0', 'reference_price' => $bid,
            'wallet_base_balance' => $baseBalance, 'managed_base_balance' => $managedBase,
            'managed_base_cap_applied' => $managedBase !== null];
    }

    private function constraints(string $amount, string $cost, array $state): ?string
    {
        if (bccomp($amount, '0', self::SCALE) <= 0) {
            return 'amount_rounds_to_zero';
        }
        if (isset($state['minimum_amount']) && bccomp($amount, $this->d($state['minimum_amount']), self::SCALE) < 0) {
            return 'below_exchange_minimum_amount';
        }
        if (isset($state['minimum_cost']) && bccomp($cost, $this->d($state['minimum_cost']), self::SCALE) < 0) {
            return 'below_exchange_minimum_cost';
        }

        return null;
    }

    private function applyStep(string $amount, mixed $step): string
    {
        if ($step === null || bccomp($this->d($step), '0', self::SCALE) <= 0) {
            return $amount;
        }
        $step = $this->d($step);
        $units = bcdiv($amount, $step, 0);

        return bcmul($units, $step, self::SCALE);
    }

    private function isCurrentModel(MarketSubscription $subscription, MarketSignal $signal): bool
    {
        $period = $subscription->market->feed?->selected_period;
        if ($period === null) {
            return false;
        }
        $key = ModelStore::marketKey($subscription->market->exchange->class, $subscription->market->symbol, $period);
        $head = DB::table('intelligence_heads')->where('market_key', $key)->value('model_id');

        return $head !== null && $signal->model_id === $head;
    }

    private function signalPayload(?MarketSignal $signal): ?array
    {
        if ($signal === null) {
            return null;
        }

        return [
            'id' => $signal->getKey(), 'period' => $signal->period, 'model_id' => $signal->model_id,
            'action' => SignalSemantics::clientAction($signal->action, $signal->reason),
            'reason' => $signal->reason, 'evidence_status' => SignalSemantics::evidenceStatus($signal->reason),
            'action_meaning' => $signal->payload['action_meaning']
                ?? SignalSemantics::actionMeaning($signal->action, $signal->reason, $signal->payload['scoring'] ?? null),
            'confidence' => in_array($signal->reason, ['supported', 'degraded_action_only'], true)
                ? (float) ($signal->payload['confidence'] ?? 0.0) : null,
            'decision_at_ms' => $signal->decision_at_ms, 'recorded_at_ms' => $signal->recorded_at_ms,
            'regime' => $signal->payload['regime'] ?? null,
            'reference_price' => $signal->payload['reference_price'] ?? null,
            'reference_price_source' => $signal->payload['reference_price_source'] ?? null,
            'prediction_input_basis' => $signal->payload['prediction_input_basis'] ?? null,
            'scoring' => $signal->payload['scoring'] ?? null,
        ];
    }

    private function minDecimal(mixed ...$values): string
    {
        $minimum = null;
        foreach ($values as $value) {
            $decimal = $this->d($value);
            if ($minimum === null || bccomp($decimal, $minimum, self::SCALE) < 0) {
                $minimum = $decimal;
            }
        }

        return $minimum ?? '0';
    }

    private function d(mixed $value): string
    {
        return Decimal::normalize($value);
    }
}
