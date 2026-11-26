<?php

namespace App\Http\Controllers\Markets;

use App\Domain\Intelligence\CoinGeckoReadiness;
use App\Domain\Intelligence\IntelligenceReadiness;
use App\Domain\Intelligence\ModelStore;
use App\Domain\Intelligence\SignalFreshness;
use App\Domain\Intelligence\SignalJournal;
use App\Domain\Intelligence\WeightedKnn;
use App\Domain\MarketData\MarketCatalog;
use App\Http\Controllers\Controller;
use App\Models\Market;
use App\Models\MarketSubscription;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

final class IntelligenceController extends Controller
{
    public function show(Request $request, string $subscription, ModelStore $models, IntelligenceReadiness $readiness, MarketCatalog $catalog, CoinGeckoReadiness $contextReadiness, SignalFreshness $freshness): View
    {
        $item = MarketSubscription::query()->with('market.exchange', 'market.feed', 'market.latestSignal')
            ->where('active', true)->when(! $request->user()->isOwner(),
                fn ($query) => $query->where('user_id', $request->user()->user_id))->findOrFail($subscription);
        $period = $item->market->feed?->selected_period;
        $coingecko = $contextReadiness->forMarkets([$item->market])->get(ModelStore::marketKey(
            $item->market->exchange->class, $item->market->symbol, $period ?? ''));
        $report = null;
        $trainingReport = null;
        $signal = [...WeightedKnn::abstain($period === null ? 'period_pending' : 'no_model'), 'patterns' => []];
        if ($period !== null) {
            try {
                $report = $models->currentReport($item->market->exchange->class, $item->market->symbol, $period);
                $trainingReport = $models->latestReport($item->market->exchange->class, $item->market->symbol, $period);
                $modelReady = ModelStore::isReadyReport($report);
                $reason = match (true) {
                    $report === null => 'no_model',
                    ! $modelReady => ModelStore::knnReadiness($report)['outcome']['reason']
                        ?? ($report['reason'] ?? 'model_unavailable'),
                    default => 'awaiting_recording',
                };
                $signal = [...WeightedKnn::abstain($reason), 'patterns' => []];

                $recorded = $item->market->latestSignal;
                $nowMs = now()->getTimestampMs();
                $expiresAt = $recorded === null ? null : $freshness->expiresAt($recorded->decision_at_ms, $period);
                $needsFreshDecision = $recorded !== null
                    && in_array($recorded->reason, ['supported', 'degraded_action_only'], true);
                $decisionModelReady = $recorded !== null && match ($recorded->reason) {
                    'supported' => $modelReady,
                    'degraded_action_only' => ModelStore::knnReadiness($report)['action']['ready'] ?? false,
                    default => false,
                };
                if ($recorded !== null && $recorded->period === $period
                    && $recorded->model_id === ($report['model_id'] ?? null)
                    && (! $needsFreshDecision
                        || ($decisionModelReady && SignalJournal::hasDecision($recorded->action, $recorded->reason)
                            && $recorded->decision_at_ms !== null && $recorded->decision_at_ms <= $nowMs
                            && $expiresAt !== null && $nowMs < $expiresAt))) {
                    $signal = $recorded->payload;
                    $signal['patterns'] ??= [];
                }
            } catch (Throwable $error) {
                report($error);
                $signal = [...WeightedKnn::abstain('model_unavailable'), 'patterns' => []];
            }
        }
        $explanation = SignalJournal::explain($signal['reason']);
        if ($signal['reason'] === 'no_model' && $trainingReport !== null) {
            $explanation = 'No model is published for production. Review the latest training validation below.';
        }

        $trainingStates = ModelStore::knnReadiness($trainingReport);
        $trainingReason = $period === null ? 'period_pending'
            : (! $trainingStates['outcome']['ready'] ? $trainingStates['outcome']['reason']
                : (! $trainingStates['action']['ready'] ? $trainingStates['action']['reason'] : 'awaiting_recording'));
        $progress = $readiness->describe($item->market->exchange->class, $item->market->symbol, $period,
            $item->market->feed, $trainingReport, $signal, $trainingReason);
        $signalSettings = $report['settings'] ?? config('intelligence.knn');

        $exchangeChoices = collect();
        try {
            $exchangeChoices = collect($catalog->exchanges())->keyBy('value');
        } catch (Throwable $error) {
            report($error);
        }
        $peerMarkets = Market::query()
            ->with(['exchange', 'feed', 'subscriptions' => fn ($query) => $query
                ->where('user_id', $request->user()->user_id)->where('active', true)])
            ->where('symbol', $item->market->symbol)
            ->where('market_id', '!=', $item->market_id)
            ->get()
            ->filter(fn (Market $market): bool => $exchangeChoices->isEmpty() || $exchangeChoices->has($market->exchange->class))
            ->map(function (Market $market) use ($exchangeChoices): array {
                $choice = $exchangeChoices->get($market->exchange->class, []);

                return [
                    'exchange' => $market->exchange,
                    'label' => $choice['label'] ?? $market->exchange->name,
                    'logo_url' => $choice['logo_url'] ?? null,
                    'period' => $market->feed?->selected_period,
                    'following' => $market->subscriptions->isNotEmpty(),
                ];
            })
            ->sortBy(fn (array $peer): string => mb_strtolower($peer['label']))
            ->values();

        return view('markets.intelligence', compact('item', 'period', 'signal', 'report', 'trainingReport', 'explanation',
            'progress', 'signalSettings', 'peerMarkets', 'coingecko'));
    }
}
