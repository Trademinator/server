<?php

namespace App\Domain\Client;

use App\Domain\MarketData\CandleTimeframe;
use App\Helpers\Decimal;
use App\Models\ClientPaperAccount;
use App\Models\ClientPaperEvent;
use App\Models\MarketSignal;
use App\Models\MarketSubscription;
use App\Repositories\TickerRepository;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class ClientPaperTrading
{
    private const SCALE = 18;

    public function __construct(
        private PaperDecisionService $decisions,
        private PaperExecutionModel $execution,
        private TickerRepository $tickers,
        private CandleTimeframe $timeframe,
    ) {}

    public function execute(MarketSubscription $subscription, array $input): array
    {
        return DB::transaction(function () use ($subscription, $input): array {
            // Serialize first calls on the existing parent before reading settings or an absent account.
            $subscription = MarketSubscription::query()->where('user_id', $subscription->user_id)
                ->whereKey($subscription->getKey())->lockForUpdate()->firstOrFail();

            $subscription->loadMissing('clientSetting', 'market.latestSignal');
            $setting = $subscription->clientSetting;
            abort_if($setting === null, 409, 'Client market settings are not configured for this market.');

            $hash = hash('sha256', json_encode($this->canonical($input), JSON_THROW_ON_ERROR));
            $account = ClientPaperAccount::query()->where('user_id', $subscription->user_id)
                ->where('market_subscription_id', $subscription->getKey())->lockForUpdate()->first();

            if ($account !== null) {
                $existing = ClientPaperEvent::query()->where('client_paper_account_id', $account->getKey())
                    ->where('idempotency_key', $input['idempotency_key'])->first();
                if ($existing !== null) {
                    if (! hash_equals($existing->request_hash, $hash)) {
                        throw new ConflictHttpException('This paper idempotency key was already used with different data.');
                    }

                    return $existing->result;
                }
            }

            $bid = $this->d($input['best_bid']);
            $ask = $this->d($input['best_ask']);
            $feeBps = $this->d($input['taker_fee_bps']);
            $feeAsset = $input['fee_asset'] ?? 'quote';
            $slippageBps = $this->d($setting->paper_slippage_bps ?? config('client.default_paper_slippage_bps'));
            if ($account === null) {
                $initial = $this->d($setting->paper_initial_quote ?: config('client.default_paper_quote'));
                $benchmark = $this->execution->benchmark($initial, $ask, $feeBps, $slippageBps, $feeAsset);
                $account = ClientPaperAccount::query()->create([
                    'user_id' => $subscription->user_id,
                    'market_subscription_id' => $subscription->getKey(),
                    'quote_balance' => $initial,
                    'base_balance' => '0',
                    'initial_quote_balance' => $initial,
                    'benchmark_base_quantity' => $benchmark['base_quantity'],
                    'benchmark_start_price' => $benchmark['price'],
                    'peak_equity' => $initial,
                    'realized_fees_quote' => '0',
                    'started_at_ms' => (int) $input['reported_at_ms'],
                ]);
            }

            $signalId = $subscription->market->latestSignal?->getKey();
            $signalAlreadyActed = $signalId !== null
                && ClientPaperEvent::query()->where('client_paper_account_id', $account->getKey())
                    ->where('market_signal_id', $signalId)->where('event', 'executed')->exists();

            $positionQuote = bcmul($this->d($account->base_balance), $bid, self::SCALE);
            $state = [...$input,
                'quote_balance' => $account->quote_balance,
                'base_balance' => $account->base_balance,
                'position_quote' => $positionQuote,
                'conflicting_exposure' => false,
            ];
            $decision = $this->decisions->evaluate($subscription, $state);
            $event = 'skipped';
            $reason = $decision['reason'];
            $side = null;
            $quantity = null;
            $price = null;
            $feeQuote = '0';
            $feeBase = '0';
            $feeAmount = '0';
            $feeQuoteEquivalent = '0';

            if ($signalAlreadyActed) {
                $decision = [...$decision, 'eligible' => false, 'reason' => 'signal_already_acted'];
                $reason = 'signal_already_acted';
            } elseif ($decision['eligible']) {
                $side = $decision['action'];
                $fill = $this->execution->fill(
                    $side,
                    $this->d($decision['sizing']['base_amount']),
                    $this->d($decision['sizing']['quote_amount']),
                    $bid,
                    $ask,
                    $feeBps,
                    $slippageBps,
                    $feeAsset,
                    $input['amount_step'] ?? null,
                    $input['minimum_amount'] ?? null,
                    $input['minimum_cost'] ?? null,
                );
                if (! $fill['eligible']) {
                    $decision = [...$decision, 'eligible' => false, 'reason' => $fill['reason']];
                    $reason = $fill['reason'];
                } else {
                    $nextQuote = bcadd(
                        bcsub($this->d($account->quote_balance), $fill['quote_debit'], self::SCALE),
                        $fill['quote_credit'],
                        self::SCALE
                    );
                    $nextBase = bcadd(
                        bcsub($this->d($account->base_balance), $fill['base_debit'], self::SCALE),
                        $fill['base_credit'],
                        self::SCALE
                    );
                    if (bccomp($nextQuote, '0', self::SCALE) < 0 || bccomp($nextBase, '0', self::SCALE) < 0) {
                        $decision = [...$decision, 'eligible' => false, 'reason' => 'paper_balance_changed'];
                        $reason = 'paper_balance_changed';
                    } else {
                        $account->quote_balance = $nextQuote;
                        $account->base_balance = $nextBase;
                        $quantity = $fill['quantity'];
                        $price = $fill['price'];
                        $feeQuote = $fill['fee_quote'];
                        $feeBase = $fill['fee_base'];
                        $feeAmount = $fill['fee_amount'];
                        $feeQuoteEquivalent = $fill['fee_quote_equivalent'];
                        $event = 'executed';
                        $reason = 'paper_fill';
                    }
                }
            }

            if ($event === 'executed') {
                $account->realized_fees_quote = bcadd(
                    $this->d($account->realized_fees_quote),
                    $feeQuoteEquivalent,
                    self::SCALE
                );
            }
            $valuation = $this->recordValuation($account, [
                'price' => $bid, 'source' => 'client_bid', 'observed_at_ms' => (int) $input['reported_at_ms'],
            ]);
            $account->save();

            $summary = $this->summary($account, $valuation);
            $result = [
                'api_version' => 1,
                'event' => $event,
                'reason' => $reason,
                'signal_id' => $signalId,
                'side' => $event === 'executed' ? $side : null,
                'quantity' => $event === 'executed' ? $quantity : null,
                'price' => $event === 'executed' ? $price : null,
                'fee_asset' => $event === 'executed' ? $feeAsset : null,
                'fee_amount' => $event === 'executed' ? $feeAmount : '0',
                'fee_quote' => $event === 'executed' ? $feeQuote : '0',
                'fee_base' => $event === 'executed' ? $feeBase : '0',
                'fee_quote_equivalent' => $event === 'executed' ? $feeQuoteEquivalent : '0',
                'execution_assumptions' => [
                    'depth_aware' => false,
                    'slippage_model' => 'fixed_bps_from_top_of_book',
                    'slippage_bps' => (float) $slippageBps,
                    'fee_bps' => (float) $feeBps,
                    'fee_asset' => $feeAsset,
                ],
                'decision' => $decision,
                'paper' => $summary,
            ];

            ClientPaperEvent::query()->create([
                'client_paper_account_id' => $account->getKey(),
                'market_signal_id' => $signalId,
                'idempotency_key' => $input['idempotency_key'],
                'request_hash' => $hash,
                'event' => $event,
                'reason' => $reason,
                'side' => $event === 'executed' ? $side : null,
                'quantity' => $event === 'executed' ? $quantity : null,
                'price' => $event === 'executed' ? $price : null,
                'fee_quote' => $event === 'executed' ? $feeQuote : null,
                'occurred_at_ms' => (int) $input['reported_at_ms'],
                'recorded_at_ms' => now()->getTimestampMs(),
                'result' => $result,
            ]);

            return $result;
        }, 3);
    }

    public function current(MarketSubscription $subscription, ?string $bid = null): ?array
    {
        return DB::transaction(function () use ($subscription, $bid): ?array {
            $account = ClientPaperAccount::query()->where('user_id', $subscription->user_id)
                ->where('market_subscription_id', $subscription->getKey())->lockForUpdate()->first();
            if ($account === null) {
                return null;
            }

            $valuation = $bid === null ? $this->marketValuation($subscription, $this->savedValuation($account)) : [
                'price' => $this->d($bid), 'source' => 'client_bid', 'observed_at_ms' => now()->getTimestampMs(),
            ];
            if ($valuation !== null) {
                $valuation = $this->recordValuation($account, $valuation);
                if ($account->isDirty()) {
                    $account->save();
                }
            }

            return $this->summary($account, $valuation);
        }, 3);
    }

    /**
     * @param  array{price: string, source: string, observed_at_ms: int}|null  $valuation
     * @return array{price: string, source: string, observed_at_ms: int}|null
     */
    private function marketValuation(MarketSubscription $subscription, ?array $valuation): ?array
    {
        $subscription->loadMissing('market.exchange', 'market.feed');
        $market = $subscription->market;
        $period = $market->feed?->selected_period;
        if (! in_array($period, CandleTimeframe::SUPPORTED, true)) {
            return $valuation;
        }

        $now = now()->getTimestampMs();
        $signal = MarketSignal::query()->where('market_id', $market->getKey())->where('period', $period)
            ->where('decision_at_ms', '>', 0)->where('decision_at_ms', '<=', $now)
            ->where('recorded_at_ms', '<=', $now)
            ->orderByDesc('recorded_at_ms')->orderByDesc('market_signal_id')->first();
        $price = $this->usablePrice($signal?->payload['reference_price'] ?? null);
        if ($price !== null && ($valuation === null || $signal->decision_at_ms > $valuation['observed_at_ms'])) {
            $valuation = ['price' => $price, 'source' => 'signal_reference_price', 'observed_at_ms' => $signal->decision_at_ms];
        }

        $exchange = $market->exchange->class;
        $timestamps = $this->tickers->pageTimestamps($exchange, $market->symbol, $period, 'latest', null, $now, 2);
        $latest = $this->tickers->latestTimestamp($exchange, $market->symbol, $period);
        if ($latest !== null) {
            $timestamps[] = $latest;
        }
        $timestamps = array_unique($timestamps);
        rsort($timestamps, SORT_NUMERIC);

        foreach ($timestamps as $timestamp) {
            $closedAt = $this->timeframe->next($timestamp, $period);
            if ($timestamp < 0 || $closedAt > $now) {
                continue;
            }
            if ($valuation !== null && ($closedAt < $valuation['observed_at_ms']
                || ($closedAt === $valuation['observed_at_ms'] && $valuation['source'] === 'client_bid'))) {
                break;
            }
            foreach ($this->tickers->streamHistory($exchange, $market->symbol, $period, $timestamp, $timestamp) as $candle) {
                $price = $this->usablePrice($candle['close'] ?? null);
                if ($price !== null) {
                    return ['price' => $price, 'source' => 'closed_candle_close', 'observed_at_ms' => $closedAt];
                }
            }
        }

        return $valuation;
    }

    private function usablePrice(mixed $value): ?string
    {
        if (! is_numeric($value) || ! is_finite((float) $value)) {
            return null;
        }

        $price = $this->d($value);

        return bccomp($price, '0', self::SCALE) > 0 ? $price : null;
    }

    private function equity(ClientPaperAccount $account, string $price): string
    {
        return bcadd($this->d($account->quote_balance), bcmul($this->d($account->base_balance), $price, self::SCALE), self::SCALE);
    }

    /** @return array{price: string, source: string, observed_at_ms: int}|null */
    private function savedValuation(ClientPaperAccount $account): ?array
    {
        $price = $this->usablePrice($account->valuation_price);
        $observedAt = $account->valuation_at_ms;
        if ($price === null || $observedAt === null || $observedAt <= 0 || $observedAt > now()->getTimestampMs()
            || $account->valuation_source === null) {
            return null;
        }

        return ['price' => $price, 'source' => $account->valuation_source, 'observed_at_ms' => $observedAt];
    }

    /**
     * @param  array{price: string, source: string, observed_at_ms: int}  $valuation
     * @return array{price: string, source: string, observed_at_ms: int}
     */
    private function recordValuation(ClientPaperAccount $account, array $valuation): array
    {
        $saved = $this->savedValuation($account);
        if ($saved !== null && $saved['observed_at_ms'] > $valuation['observed_at_ms']) {
            $valuation = $saved;
        }
        $account->valuation_price = $valuation['price'];
        $account->valuation_source = $valuation['source'];
        $account->valuation_at_ms = $valuation['observed_at_ms'];

        $equity = $this->equity($account, $valuation['price']);
        if (bccomp($equity, $this->d($account->peak_equity), self::SCALE) > 0) {
            $account->peak_equity = $equity;
        }

        return $valuation;
    }

    /** @param array{price: string, source: string, observed_at_ms: int}|null $valuation */
    private function summary(ClientPaperAccount $account, ?array $valuation): array
    {
        $price = $valuation['price'] ?? null;
        $equity = $price === null ? null : $this->equity($account, $price);
        $initial = $this->d($account->initial_quote_balance);
        $benchmark = $price === null ? null : bcmul($this->d($account->benchmark_base_quantity), $price, self::SCALE);
        $peak = $this->d($account->peak_equity);
        $drawdown = $equity === null ? null : (bccomp($peak, '0', self::SCALE) > 0
            ? bcmul(bcdiv(bcsub($peak, $equity, self::SCALE), $peak, self::SCALE), '100', 8) : '0');
        $return = $equity === null ? null : (bccomp($initial, '0', self::SCALE) > 0
            ? bcmul(bcdiv(bcsub($equity, $initial, self::SCALE), $initial, self::SCALE), '100', 8) : '0');
        $benchmarkReturn = $benchmark === null ? null : (bccomp($initial, '0', self::SCALE) > 0
            ? bcmul(bcdiv(bcsub($benchmark, $initial, self::SCALE), $initial, self::SCALE), '100', 8) : '0');
        $events = $account->events()->count();
        $fills = $account->events()->where('event', 'executed')->count();

        return [
            'account_id' => $account->getKey(),
            'quote_balance' => $account->quote_balance,
            'base_balance' => $account->base_balance,
            'equity_quote' => $equity,
            'initial_quote_balance' => $account->initial_quote_balance,
            'return_pct' => $return === null ? null : (float) $return,
            'peak_equity_quote' => $account->peak_equity,
            'drawdown_pct' => $drawdown === null ? null : (float) $drawdown,
            'valuation' => [
                'price' => $price,
                'source' => $valuation['source'] ?? 'unavailable',
                'observed_at_ms' => $valuation['observed_at_ms'] ?? null,
                'age_seconds' => $valuation === null ? null : max(0, intdiv(now()->getTimestampMs() - $valuation['observed_at_ms'], 1000)),
            ],
            'fees_quote' => $account->realized_fees_quote,
            'fees_quote_equivalent' => $account->realized_fees_quote,
            'benchmark' => [
                'method' => 'passive_buy_and_hold_after_one_entry_fee',
                'start_price' => $account->benchmark_start_price,
                'equity_quote' => $benchmark,
                'return_pct' => $benchmarkReturn === null ? null : (float) $benchmarkReturn,
            ],
            'sample_size' => ['evaluations' => $events, 'fills' => $fills],
            'window' => ['started_at_ms' => $account->started_at_ms, 'as_of_ms' => now()->getTimestampMs()],
        ];
    }

    private function canonical(array $input): array
    {
        ksort($input);

        return $input;
    }

    private function d(mixed $value): string
    {
        return Decimal::normalize($value);
    }
}
