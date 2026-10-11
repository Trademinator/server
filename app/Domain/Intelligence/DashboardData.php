<?php

namespace App\Domain\Intelligence;

use App\Domain\MarketData\CandleTimeframe;
use App\Domain\MarketData\ExchangeMetadata;
use App\Domain\MarketData\MarketCatalog;
use App\Domain\MarketData\MarketCatalogException;
use App\Domain\MarketData\MarketChart;
use App\Domain\MarketSuggestions\MarketDiscovery;
use App\Models\HumanCandleLabel;
use App\Models\Market;
use App\Models\MarketSignal;
use App\Models\MarketSubscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class DashboardData
{
    public function __construct(private MarketChart $charts, private IntelligenceReadiness $readiness,
        private CandleTimeframe $timeframe, private MarketDiscovery $discovery, private CoinGeckoReadiness $contextReadiness,
        private SignalFreshness $freshness) {}

    public function subscriptions(User $user): Builder
    {
        return MarketSubscription::query()->where('user_id', $user->user_id)->where('active', true)
            ->with('market.exchange', 'market.feed', 'market.latestSignal');
    }

    public function accessibleSubscriptions(User $user): Builder
    {
        return MarketSubscription::query()->where('active', true)
            ->when(! $user->isOwner(), fn (Builder $query) => $query->where('user_id', $user->user_id))
            ->with('market.exchange', 'market.feed', 'market.latestSignal');
    }

    public function markets(User $user, ?string $selectedId = null, string $search = '', string $scope = 'mine',
        bool $favoritesOnly = false): array
    {
        $query = $this->scopedSubscriptions($user, $scope, $favoritesOnly)
            ->withExists(['favoritedBy as is_favorite' => fn (Builder $favorites) => $favorites->where('users.user_id', $user->user_id)]);
        if ($search !== '') {
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)).'%';
            $query->whereHas('market', fn ($market) => $market->where(function ($match) use ($term): void {
                $match->whereRaw("LOWER(symbol) LIKE ? ESCAPE '!'", [$term])
                    ->orWhereHas('exchange', fn ($exchange) => $exchange->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$term]))
                    ->orWhereHas('feed', fn ($feed) => $feed->whereRaw("LOWER(selected_period) LIKE ? ESCAPE '!'", [$term]));
            }));
        }
        $subscriptions = $query->orderBy('created_at')->orderBy('market_subscription_id')
            ->paginate(max(1, min(24, (int) config('dashboard.page_size'))))->withQueryString();
        $reports = $this->reports($subscriptions->getCollection()->map(fn ($item) => $this->key($item->market))->all());
        $contexts = $this->contextReadiness->forMarkets($subscriptions->getCollection()->pluck('market'));
        try {
            $metadata = app(ExchangeMetadata::class)->all();
        } catch (MarketCatalogException) {
            $metadata = [];
        }
        $canManage = $user->can('manage-server');
        $cards = $subscriptions->getCollection()->map(function (MarketSubscription $item) use ($reports, $contexts, $metadata, $canManage, $user): array {
            $chart = $this->charts->data($item->market, 48);
            $report = $reports->get($this->key($item->market));
            $ready = $this->ready($report);
            $signal = $item->market->latestSignal;
            $fresh = $this->fresh($item->market, $signal, $report);
            $issues = $canManage ? app(CollectionAttention::class)->describe($item->market, $chart) : [];

            return ['subscription' => $item, 'chart' => $chart, 'report' => $report, 'ready' => $ready,
                'coingecko' => $contexts->get($this->key($item->market)),
                'is_mine' => $item->user_id === $user->user_id,
                'is_favorite' => (bool) $item->is_favorite,
                'logo_url' => MarketCatalog::logoUrl($metadata[$item->market->exchange->class]['logo'] ?? null),
                'signal' => $signal, 'signal_fresh' => $fresh, 'attention' => $issues !== [], 'issues' => $issues,
                'sparkline' => $this->sparkline($chart), 'label' => $fresh && $signal !== null
                    ? SignalJournal::label($signal->action, $signal->reason) : 'Waiting for evidence'];
        });

        return compact('subscriptions', 'cards', 'selectedId', 'search', 'scope', 'favoritesOnly');
    }

    public function chart(User $user, Market $market, int $limit = 360): array
    {
        $chart = $this->charts->data($market, $limit);
        $first = $chart['series'][0]['time'] ?? null;
        $lastIndex = array_key_last($chart['series']);
        $last = $lastIndex === null ? null : $chart['series'][$lastIndex]['time'];
        $chart['human_labels'] = $first === null || $last === null
            ? [] : $this->humanLabels($user, $market, $first * 1000, $last * 1000);

        return $chart;
    }

    public function humanLabels(User $user, Market $market, int $fromMs, int $toMs): array
    {
        $period = $market->feed?->selected_period;
        if (! $user->can('train-intelligence') || ! in_array($period, CandleTimeframe::SUPPORTED, true) || $toMs < $fromMs) {
            return [];
        }

        $fromDecision = $this->timeframe->next($fromMs, $period);
        $toDecision = $this->timeframe->next($toMs, $period);
        $labels = HumanCandleLabel::query()
            ->join('human_training_snapshots as snapshots', 'snapshots.snapshot_id', '=', 'human_candle_labels.snapshot_id')
            ->where('human_candle_labels.trainer_id', $user->user_id)
            ->where('snapshots.market_key', ModelStore::marketKey($market->exchange->class, $market->symbol, $period))
            ->where('snapshots.version', HumanTraining::VERSION)
            ->whereBetween('snapshots.decision_at_ms', [$fromDecision, $toDecision])
            ->whereIn('human_candle_labels.action', ['buy', 'hold', 'sell'])
            ->orderByDesc('human_candle_labels.updated_at')
            ->get(['snapshots.decision_at_ms', 'human_candle_labels.action']);

        return $labels->map(fn ($label): array => [
            'time' => intdiv($this->timeframe->previous((int) $label->decision_at_ms, $period), 1000),
            'action' => $label->action,
        ])->filter(fn (array $label): bool => $fromMs <= $label['time'] * 1000 && $toMs >= $label['time'] * 1000)
            ->unique('time')->sortBy('time')->values()->all();
    }

    private function reports(array $keys): Collection
    {
        return DB::table('intelligence_heads as heads')->join('intelligence_models as models', 'models.model_id', '=', 'heads.model_id')
            ->whereIn('heads.market_key', array_unique($keys))->get(['heads.market_key', 'models.report'])
            ->mapWithKeys(fn ($row) => [$row->market_key => json_decode($row->report, true, flags: JSON_THROW_ON_ERROR)]);
    }

    public function overview(User $user, ?string $selectedId, int $since, string $search = '', string $scope = 'mine',
        bool $favoritesOnly = false): array
    {
        $page = $this->markets($user, $selectedId, $search, $scope, $favoritesOnly);
        ['subscriptions' => $subscriptions, 'cards' => $cards] = $page;
        $selected = $selectedId === null ? $subscriptions->first()
            : $this->scopedSubscriptions($user, $scope, $favoritesOnly)->find($selectedId);
        $selected ??= $subscriptions->first();
        $keys = $subscriptions->getCollection()->map(fn ($item) => $this->key($item->market))->all();
        if ($selected !== null) {
            $keys[] = $this->key($selected->market);
        }
        $reports = $this->reports($keys);
        $totals = ['followed' => 0, 'outcome' => 0, 'action' => 0, 'coingecko' => 0];
        $this->scopedSubscriptions($user, $scope, false)->setEagerLoads([])->with('market.exchange', 'market.feed')
            ->chunkById(100, function ($items) use (&$totals): void {
                $reports = $this->reports($items->map(fn ($item) => $this->key($item->market))->all());
                $contexts = $this->contextReadiness->forMarkets($items->pluck('market'));
                foreach ($items as $item) {
                    $totals['followed']++;
                    $totals['coingecko'] += (int) ($contexts->get($this->key($item->market))['ready'] ?? false);
                    foreach (ModelStore::knnReadiness($reports->get($this->key($item->market))) as $name => $state) {
                        $totals[$name] += (int) $state['ready'];
                    }
                }
            }, 'market_subscription_id');
        $changes = MarketSignal::query()->where('is_change', true)->where('recorded_at_ms', '>', $since)
            ->where('recorded_at_ms', '<=', now()->getTimestampMs())
            ->whereHas('market.subscriptions', function (Builder $query) use ($user, $scope, $favoritesOnly): void {
                $query->where('active', true);
                if (! $user->isOwner() || $scope !== 'all') {
                    $query->where('user_id', $user->user_id);
                }
                if ($favoritesOnly) {
                    $query->whereHas('favoritedBy',
                        fn (Builder $favorites) => $favorites->where('users.user_id', $user->user_id));
                }
            });
        $changeCount = (clone $changes)->count();
        $timeline = $changes->with('market.exchange')->orderByDesc('recorded_at_ms')->orderByDesc('market_signal_id')->limit(20)->get();
        $details = null;
        if ($selected !== null) {
            $market = $selected->market;
            $report = $reports->get($this->key($market));
            $signal = $market->latestSignal;
            $fresh = $this->fresh($market, $signal, $report);
            $current = $fresh ? $signal->payload : WeightedKnn::abstain($market->feed?->selected_period === null ? 'period_pending'
                : ($report === null ? 'no_model' : ($this->ready($report) ? 'awaiting_recording' : ($report['reason'] ?? 'model_unavailable'))));
            $cacheKey = 'trademinator:dashboard-readiness:v2:'.hash('sha256', json_encode([
                $this->key($market), $report['model_id'] ?? null, $current['reason'], $market->feed?->updated_at?->getTimestamp(),
            ], JSON_THROW_ON_ERROR));
            $progress = Cache::remember($cacheKey, 60, fn () => $this->readiness->describe($market->exchange->class,
                $market->symbol, $market->feed?->selected_period, $market->feed, $report, $current));
            $details = ['subscription' => $selected, 'report' => $report, 'signal' => $signal, 'signal_fresh' => $fresh,
                'coingecko' => $this->contextReadiness->forMarkets([$market])->get($this->key($market)),
                'progress' => $progress, 'chart' => $this->chart($user, $market),
                'history' => MarketSignal::query()->where('market_id', $market->getKey())
                    ->orderByDesc('recorded_at_ms')->orderByDesc('market_signal_id')->limit(20)->get()];
        }
        $overlap = $cards->groupBy(fn (array $card): string => explode('/', $card['subscription']->market->symbol)[0])
            ->filter(fn ($group): bool => $group->count() > 1)->map->count();

        return compact('subscriptions', 'cards', 'details', 'timeline', 'changeCount', 'overlap', 'since', 'totals', 'search',
            'scope', 'favoritesOnly')
            + ['conditions' => $this->discovery->snapshot(), 'selectedId' => $selected?->getKey()];
    }

    private function scopedSubscriptions(User $user, string $scope, bool $favoritesOnly): Builder
    {
        $query = $user->isOwner() && $scope === 'all' ? $this->accessibleSubscriptions($user) : $this->subscriptions($user);
        if ($favoritesOnly) {
            $query->whereHas('favoritedBy', fn (Builder $favorites) => $favorites->where('users.user_id', $user->user_id));
        }

        return $query;
    }

    private function key(Market $market): string
    {
        return ModelStore::marketKey($market->exchange->class, $market->symbol, $market->feed?->selected_period ?? '');
    }

    private function ready(?array $report): bool
    {
        return ModelStore::isReadyReport($report);
    }

    private function fresh(Market $market, ?MarketSignal $signal, ?array $report): bool
    {
        if ($signal === null || $signal->period !== $market->feed?->selected_period
            || $signal->model_id !== ($report['model_id'] ?? null)) {
            return false;
        }
        if (! in_array($signal->reason, ['supported', 'degraded_action_only'], true)) {
            return true;
        }
        if (! SignalJournal::hasDecision($signal->action, $signal->reason)) {
            return false;
        }
        $ready = $signal->reason === 'supported'
            ? $this->ready($report)
            : (ModelStore::knnReadiness($report)['action']['ready'] ?? false);
        if (! $ready || $signal->decision_at_ms === null) {
            return false;
        }
        $nowMs = now()->getTimestampMs();
        $expires = $this->freshness->expiresAt($signal->decision_at_ms, $signal->period);

        return $signal->decision_at_ms <= $nowMs && $expires !== null && $nowMs < $expires;
    }

    private function sparkline(array $chart): ?string
    {
        $prices = array_column($chart['series'], 'close');
        if (count($prices) < 2 || $chart['gaps'] > 0 || $chart['invalid_candles'] > 0) {
            return null;
        }
        $minimum = min($prices);
        $span = max($prices) - $minimum;
        $points = [];
        foreach ($prices as $i => $price) {
            $points[] = round($i * 200 / (count($prices) - 1), 2).','.round($span > 0 ? 44 - 40 * ($price - $minimum) / $span : 24, 2);
        }

        return implode(' ', $points);
    }
}
