<?php

namespace App\Http\Controllers\Markets;

use App\Domain\Intelligence\CoinGeckoReadiness;
use App\Domain\Intelligence\ModelStore;
use App\Domain\MarketData\MarketCatalog;
use App\Domain\MarketData\MarketCatalogException;
use App\Domain\MarketData\MarketSubscriptions;
use App\Http\Controllers\Controller;
use App\Models\Exchange;
use App\Models\MarketSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use InvalidArgumentException;
use Throwable;

final class SubscriptionController extends Controller
{
    public function index(Request $request, MarketCatalog $catalog, CoinGeckoReadiness $contextReadiness, ModelStore $models): View
    {
        $catalogueError = null;
        try {
            $exchanges = $catalog->exchanges();
        } catch (Throwable $exception) {
            $failure = MarketCatalogException::reportFailure($exception, 'exchange-list');
            $catalogueError = $failure['message'].' Reference: '.$failure['reference'];
            $exchanges = [];
        }
        $exchangeChoices = collect($exchanges)->keyBy('value');
        $subscriptions = MarketSubscription::query()->with('market.exchange', 'market.feed')
            ->where('user_id', $request->user()->user_id)->get();
        $marketKeys = $subscriptions->mapWithKeys(function (MarketSubscription $item): array {
            $period = $item->market->feed?->selected_period;

            return [$item->getKey() => $period === null ? null
                : ModelStore::marketKey($item->market->exchange->class, $item->market->symbol, $period)];
        });
        $keys = $marketKeys->filter()->unique()->values();
        $modelReports = collect();
        if ($keys->isNotEmpty()) {
            $modelReports = DB::table('intelligence_heads as heads')
                ->join('intelligence_models as models', 'models.model_id', '=', 'heads.model_id')
                ->whereIn('heads.market_key', $keys->all())->get(['heads.market_key', 'models.report'])
                ->mapWithKeys(fn ($row): array => [$row->market_key => json_decode($row->report, true, flags: JSON_THROW_ON_ERROR)]);
        }
        $subscriptionReports = $marketKeys->map(
            fn (?string $key): ?array => $key === null ? null : $modelReports->get($key)
        );
        $trainingReports = $models->latestReports($keys->all());
        $subscriptionTrainingReports = $marketKeys->map(
            fn (?string $key): ?array => $key === null ? null : $trainingReports->get($key)
        );
        $contexts = $contextReadiness->forMarkets($subscriptions->pluck('market'));
        $subscriptionContexts = $subscriptions->mapWithKeys(fn (MarketSubscription $item): array => [
            $item->getKey() => $contexts->get(ModelStore::marketKey($item->market->exchange->class,
                $item->market->symbol, $item->market->feed?->selected_period ?? '')),
        ]);
        $subscriptionGroups = $subscriptions
            ->groupBy(fn (MarketSubscription $item): string => $item->market->exchange_id)
            ->map(function ($items) use ($exchangeChoices): array {
                $exchange = $items->first()->market->exchange;
                $choice = $exchangeChoices->get($exchange->class);

                return [
                    'exchange_id' => $exchange->exchange_id,
                    'name' => $choice['label'] ?? $exchange->name,
                    'logo_url' => $choice['logo_url'] ?? null,
                    'subscriptions' => $items->sort(fn (MarketSubscription $a, MarketSubscription $b): int => strcasecmp($a->market->symbol, $b->market->symbol)
                        ?: strcasecmp($a->market->feed->selected_period ?? '', $b->market->feed->selected_period ?? '')
                        ?: strcmp($a->market_subscription_id, $b->market_subscription_id))->values(),
                ];
            })
            ->sort(fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']) ?: strcmp($a['exchange_id'], $b['exchange_id']))->values();

        return view('markets.index', [
            'exchanges' => $exchanges,
            'catalogueError' => $catalogueError,
            'subscriptionGroups' => $subscriptionGroups,
            'subscriptionReports' => $subscriptionReports,
            'subscriptionTrainingReports' => $subscriptionTrainingReports,
            'subscriptionContexts' => $subscriptionContexts,
            'prefillExchange' => is_string($request->query('exchange')) ? $request->query('exchange') : '',
            'prefillSymbol' => is_string($request->query('symbol')) ? $request->query('symbol') : '',
        ]);
    }

    public function options(string $exchange, MarketCatalog $catalog): JsonResponse
    {
        $matches = Exchange::query()->where('class', $exchange)->limit(2)->get();
        if ($matches->count() !== 1) {
            abort(404);
        }
        try {
            return response()->json($catalog->forExchange($matches->first()));
        } catch (Throwable $exception) {
            return response()->json(MarketCatalogException::reportFailure($exception, $exchange),
                MarketCatalogException::fromFailure($exception)->status);
        }
    }

    public function store(Request $request, MarketSubscriptions $subscriptions, MarketCatalog $catalog): RedirectResponse
    {
        $input = $request->validate([
            'exchange' => ['required', 'string', 'max:32'],
            'symbol' => ['required', 'string', 'max:32'],
        ]);
        $matches = Exchange::query()->where('class', $input['exchange'])->limit(2)->get();
        if ($matches->count() !== 1) {
            return back()->withInput()->withErrors(['exchange' => 'Choose one configured exchange ID.']);
        }
        try {
            $tickSize = $catalog->tickSizeFor($matches->first(), $input['symbol']);
            if ($tickSize === null) {
                throw new InvalidArgumentException('This pair has no fixed price tick size available from CCXT.');
            }
            $subscriptions->subscribe($request->user(), $matches->first(), $input['symbol'], $tickSize, spotOnly: true);
        } catch (InvalidArgumentException $exception) {
            return back()->withInput()->withErrors(['symbol' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            $failure = MarketCatalogException::reportFailure($exception, $input['exchange']);

            return back()->withInput()->withErrors(['exchange' => $failure['message'].' Reference: '.$failure['reference']]);
        }

        return redirect()->route('markets.index')->with('status', 'Market subscription active.');
    }

    public function destroy(Request $request, string $subscription, MarketSubscriptions $subscriptions): RedirectResponse
    {
        $item = MarketSubscription::query()->with('market')
            ->where('user_id', $request->user()->user_id)->findOrFail($subscription);
        $subscriptions->unsubscribe($request->user(), $item->market);

        return redirect()->route('markets.index')->with('status', 'Market subscription inactive.');
    }
}
