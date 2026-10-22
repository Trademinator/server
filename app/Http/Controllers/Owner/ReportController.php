<?php

namespace App\Http\Controllers\Owner;

use App\Domain\Features\FeatureEngine;
use App\Domain\Intelligence\CoinGeckoReadiness;
use App\Domain\Intelligence\KnowledgeWaterfall;
use App\Domain\Intelligence\ModelStore;
use App\Domain\Operations\FailedJobMarketContext;
use App\Domain\Operations\GeoLocation;
use App\Domain\Operations\QueueBacklog;
use App\Http\Controllers\Controller;
use App\Models\Market;
use App\Models\MarketFeature;
use App\Models\MarketFeed;
use App\Models\MarketSubscription;
use App\Models\Ticker;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ReportController extends Controller
{
    public function index(GeoLocation $geo, CoinGeckoReadiness $contextReadiness): View
    {
        $users = User::query()->toBase()->selectRaw('COUNT(*) AS total, COUNT(email_verified_at) AS verified, COUNT(suspended_at) AS suspended')->first();
        $subscriptions = MarketSubscription::query()->where('active', true)->count();
        $feeds = MarketFeed::query()->selectRaw('status, COUNT(*) AS total')->groupBy('status')->orderBy('status')->get();
        $overdue = MarketFeed::query()->whereHas('market.subscriptions', fn ($q) => $q->where('active', true))
            ->where('next_pull_at', '<', now()->subMinutes(15))->count();
        $modelTotals = ['total' => 0, 'outcome' => 0, 'action' => 0, 'coingecko' => 0];
        $models = DB::table('intelligence_models as models')->join('intelligence_heads as heads', 'heads.model_id', '=', 'models.model_id')
            ->select('models.model_id', 'models.report')->orderBy('models.model_id')->lazy(100);
        foreach ($models->chunk(100) as $batch) {
            $reports = $batch->map(fn ($model): array => json_decode($model->report, true, flags: JSON_THROW_ON_ERROR));
            $contexts = $contextReadiness->forStreams($reports);
            foreach ($reports as $report) {
                $modelTotals['total']++;
                $key = ModelStore::marketKey($report['exchange'] ?? '', $report['symbol'] ?? '', $report['period'] ?? '');
                $modelTotals['coingecko'] += (int) ($contexts->get($key)['ready'] ?? false);
                foreach (ModelStore::knnReadiness($report) as $name => $state) {
                    $modelTotals[$name] += (int) $state['ready'];
                }
            }
        }
        $traffic = DB::table('access_daily_stats')->where('day', now('UTC')->toDateString())
            ->selectRaw('COALESCE(SUM(requests), 0) AS total, COALESCE(SUM(CASE WHEN status_code >= 500 THEN requests ELSE 0 END), 0) AS errors')->first();
        $latestPull = MarketFeed::query()->max('last_pulled_at');
        $latestModel = DB::table('intelligence_models')->max('created_at');
        $geoStatus = $geo->status();

        return view('owner.overview', compact('users', 'subscriptions', 'feeds', 'overdue', 'modelTotals',
            'traffic', 'latestPull', 'latestModel', 'geoStatus'));
    }

    public function queueBacklog(Request $request, QueueBacklog $backlog): View
    {
        $failedFilters = $request->validate([
            'failed_sort' => ['nullable', Rule::in(['uuid', 'connection', 'exception', 'failed_at'])],
            'failed_direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);
        $failedSort = $failedFilters['failed_sort'] = $failedFilters['failed_sort'] ?? 'failed_at';
        $failedDirection = $failedFilters['failed_direction'] = $failedFilters['failed_direction'] ?? ($failedSort === 'failed_at' ? 'desc' : 'asc');
        $queueBacklog = $backlog->snapshot();
        $failedQuery = DB::connection(config('queue.failed.database'))->table(config('queue.failed.table', 'failed_jobs'))
            ->select('uuid', 'connection', 'queue', 'exception', 'failed_at', 'payload');
        foreach (match ($failedSort) {
            'connection' => ['connection', 'queue'],
            default => [$failedSort],
        } as $column) {
            $failedQuery->orderBy($column, $failedDirection);
        }
        $failed = $failedQuery->orderByDesc('id')->limit(10)->get();
        app(FailedJobMarketContext::class)->enrich($failed);
        $failed->each(function ($job): void {
            $name = trim(Str::before(Str::before((string) $job->exception, "\n"), ':'));
            $job->exception_name = $name !== '' ? $name : 'Unknown exception';
            unset($job->exception);
        });
        $queueDriver = config('queue.default');

        return view('owner.queue-backlog', compact('queueBacklog', 'failed', 'failedFilters', 'failedSort', 'failedDirection', 'queueDriver'));
    }

    public function subscriptions(Request $request): View
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', Rule::in(['active', 'inactive'])], 'user' => ['nullable', 'uuid']]);
        $items = MarketSubscription::query()->with(['user:user_id,name,email', 'market.exchange', 'market.feed'])
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->whereHas('market', fn ($market) => $market
                ->where('symbol', 'like', '%'.$term.'%')->orWhereHas('exchange', fn ($exchange) => $exchange->where('class', 'like', '%'.$term.'%'))))
            ->when($filters['user'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            ->when($filters['state'] ?? null, fn ($q, $state) => $q->where('active', $state === 'active'))
            ->orderByDesc('created_at')->orderBy('market_subscription_id')->paginate(50)->withQueryString();
        $exchanges = DB::table('market_subscriptions')->join('markets', 'markets.market_id', '=', 'market_subscriptions.market_id')
            ->join('exchanges', 'exchanges.exchange_id', '=', 'markets.exchange_id')->where('active', true)
            ->selectRaw('exchanges.class, COUNT(*) AS subscriptions, COUNT(DISTINCT market_subscriptions.user_id) AS users, COUNT(DISTINCT markets.market_id) AS markets')
            ->groupBy('exchanges.class')->orderByDesc('subscriptions')->paginate(20, ['*'], 'exchange_page');

        return view('owner.subscriptions', compact('items', 'exchanges'));
    }

    public function market(Market $market): View
    {
        $market->load('exchange', 'feed');
        $subscriptions = $market->subscriptions()->with('user:user_id,name,email')->orderByDesc('active')
            ->orderBy('market_subscription_id')->paginate(25);
        $period = $market->feed?->selected_period;
        $candles = $features = 0;
        $head = null;
        if ($period !== null) {
            $candles = Ticker::query()->where('exchange', $market->exchange->class)->where('symbol', $market->symbol)->where('period', $period)->count();
            $features = MarketFeature::query()->where('exchange', $market->exchange->class)->where('symbol', $market->symbol)->where('period', $period)
                ->where('version', FeatureEngine::VERSION)->count();
            $head = DB::table('intelligence_heads')->where('market_key', ModelStore::marketKey($market->exchange->class, $market->symbol, $period))->value('model_id');
        }
        $backfill = $period === null ? null : DB::table('market_history_backfills')
            ->where('market_id', $market->market_id)->where('period', $period)->first();

        return view('owner.market', compact('market', 'subscriptions', 'period', 'candles', 'features', 'head', 'backfill'));
    }

    public function intelligence(Request $request, CoinGeckoReadiness $contextReadiness, KnowledgeWaterfall $waterfall): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'history' => ['nullable', 'boolean'],
            'status' => ['nullable', 'string', 'max:32'],
            'sort' => ['nullable', Rule::in(['market', 'reason', 'knowledge_rows', 'k', 'created_at'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);
        $filters['q'] = trim($filters['q'] ?? '');
        $sort = $filters['sort'] = $filters['sort'] ?? 'created_at';
        $direction = $filters['direction'] = $filters['direction'] ?? ($sort === 'created_at' ? 'desc' : 'asc');
        $query = DB::table('intelligence_models as models')->leftJoin('intelligence_heads as heads', 'heads.model_id', '=', 'models.model_id')
            ->select('models.model_id', 'models.dataset_id', 'models.status', 'models.created_at', 'models.report', 'heads.model_id as current_id')
            ->when(! ($filters['history'] ?? false), function ($query): void {
                $query->whereNotNull('heads.model_id')
                    ->whereExists(function ($active): void {
                        $active->selectRaw('1')
                            ->from('market_feeds as active_feeds')
                            ->join('markets as active_markets', 'active_markets.market_id', '=', 'active_feeds.market_id')
                            ->join('exchanges as active_exchanges', 'active_exchanges.exchange_id', '=', 'active_markets.exchange_id')
                            ->whereColumn('active_feeds.selected_period', 'models.report->period')
                            ->whereColumn('active_markets.symbol', 'models.report->symbol')
                            ->whereColumn('active_exchanges.class', 'models.report->exchange');
                    });
            })
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('models.status', $status));
        $grammar = $query->getGrammar();
        foreach (preg_split('/\s+/u', $filters['q'], flags: PREG_SPLIT_NO_EMPTY) as $term) {
            $pattern = '%'.strtr(Str::lower($term), ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
            $query->where(function (Builder $search) use ($grammar, $pattern): void {
                foreach (['models.report->exchange', 'models.report->symbol', 'models.report->period',
                    'models.report->reason', 'models.status', 'models.model_id'] as $column) {
                    $search->orWhereRaw('LOWER('.$grammar->wrap($column).") LIKE ? ESCAPE '!'", [$pattern]);
                }
            });
        }
        if (in_array($sort, ['knowledge_rows', 'k'], true)) {
            $value = 'NULLIF('.$grammar->wrap('models.report->'.$sort).", 'null')";
            if ($sort === 'knowledge_rows') {
                $value = 'COALESCE('.$value.', 0)';
            }
            $query->orderByRaw($value.' IS NULL')->orderByRaw('CAST('.$value.' AS DECIMAL(20, 0)) '.$direction);
        } else {
            $columns = match ($sort) {
                'market' => ['models.report->exchange', 'models.report->symbol', 'models.report->period'],
                'reason' => ['models.report->reason'],
                default => ['models.created_at'],
            };
            foreach ($columns as $column) {
                $query->orderBy($column, $direction);
            }
        }
        $models = $query->orderBy('models.model_id')->paginate(25)->appends($filters);
        $models->through(function ($row) {
            $row->summary = json_decode($row->report, true, flags: JSON_THROW_ON_ERROR);
            unset($row->report);

            return $row;
        });
        $contexts = $contextReadiness->forStreams($models->getCollection()->pluck('summary'));
        $models->through(function ($row) use ($contexts) {
            $row->coingecko = $contexts->get(ModelStore::marketKey($row->summary['exchange'] ?? '',
                $row->summary['symbol'] ?? '', $row->summary['period'] ?? ''));

            return $row;
        });

        // Add current-stage readiness to the rows already paginated by the owner report.
        // Historical model versions keep their immutable diagnostics, not a misleading live waterfall.
        $models->through(function ($row) use ($waterfall) {
            $row->waterfall = null;
            $row->knowledge_market_id = null;
            if ($row->current_id === null) {
                return $row;
            }
            $exchange = $row->summary['exchange'] ?? null;
            $symbol = $row->summary['symbol'] ?? null;
            $period = $row->summary['period'] ?? null;
            if (! is_string($exchange) || ! is_string($symbol) || ! is_string($period)) {
                return $row;
            }
            $feed = MarketFeed::query()->where('selected_period', $period)
                ->whereHas('market', fn ($q) => $q->where('symbol', $symbol)
                    ->whereHas('exchange', fn ($exchangeQuery) => $exchangeQuery->where('class', $exchange)))
                ->whereHas('market.subscriptions', fn ($q) => $q->where('active', true))
                ->first(['market_id']);
            $row->knowledge_market_id = $feed?->market_id;
            $row->waterfall = $waterfall->describe($exchange, $symbol, $period,
                $row->summary, $row->dataset_id, $feed?->market_id);

            return $row;
        });

        // The current model table intentionally excludes markets never trained.
        // Keep those pairs visible on this SAME page, with red first-build stages.
        $untrained = collect();
        if (! ($filters['status'] ?? null) && ! ($filters['history'] ?? false)) {
            $terms = preg_split('/\s+/u', Str::lower($filters['q']), flags: PREG_SPLIT_NO_EMPTY);
            $feeds = MarketFeed::query()->with('market.exchange')->whereNotNull('selected_period')
                ->whereHas('market.subscriptions', fn ($q) => $q->where('active', true))
                ->lazyById(100, column: 'market_id');
            foreach ($feeds->chunk(100) as $batch) {
                $keys = $batch->map(fn ($feed) => ModelStore::marketKey($feed->market->exchange->class,
                    $feed->market->symbol, $feed->selected_period))->all();
                $published = DB::table('intelligence_heads')->whereIn('market_key', $keys)->pluck('market_key')->flip();
                foreach ($batch as $feed) {
                    $exchange = $feed->market->exchange->class;
                    $symbol = $feed->market->symbol;
                    $period = $feed->selected_period;
                    if (isset($published[ModelStore::marketKey($exchange, $symbol, $period)])) {
                        continue;
                    }
                    $identity = Str::lower($exchange.' '.$symbol.' '.$period);
                    if (array_any($terms, fn ($term) => ! str_contains($identity, $term))) {
                        continue;
                    }
                    $feed->waterfall = $waterfall->describe($exchange, $symbol, $period,
                        marketId: $feed->market_id);
                    $untrained->push($feed);
                    if ($untrained->count() >= 10) {
                        break 2;
                    }
                }
            }
        }

        return view('owner.intelligence', compact('models', 'filters', 'sort', 'direction', 'untrained'));
    }

    public function model(string $model, ModelStore $store, CoinGeckoReadiness $contextReadiness): View
    {
        abort_unless(Str::isUuid($model) && DB::table('intelligence_models')->where('model_id', $model)->exists(), 404);
        $report = $store->report($model);
        $coingecko = $contextReadiness->forStreams([$report])->get(ModelStore::marketKey(
            $report['exchange'] ?? '', $report['symbol'] ?? '', $report['period'] ?? ''));

        return view('owner.model', compact('report', 'model', 'coingecko'));
    }

    public function access(Request $request, GeoLocation $geo): View
    {
        $filters = $request->validate(['days' => ['nullable', 'integer', 'min:1', 'max:366']]);
        $days = min((int) ($filters['days'] ?? 30), config('operations.retention_days'));
        $from = now('UTC')->subDays($days - 1)->toDateString();
        $base = DB::table('access_daily_stats')->where('day', '>=', $from)->where('day', '<=', now('UTC')->toDateString());
        $totals = (clone $base)->selectRaw('COALESCE(SUM(requests),0) AS requests, COALESCE(SUM(duration_ms),0) AS duration_ms,
            COALESCE(SUM(CASE WHEN authenticated = 1 THEN requests ELSE 0 END),0) AS authenticated,
            COALESCE(SUM(CASE WHEN status_code >= 400 AND status_code < 500 THEN requests ELSE 0 END),0) AS client_errors,
            COALESCE(SUM(CASE WHEN status_code >= 500 THEN requests ELSE 0 END),0) AS server_errors')->first();
        $daily = (clone $base)->selectRaw('day, SUM(requests) AS requests')->groupBy('day')->orderBy('day')->get();
        $countries = (clone $base)->selectRaw('country, SUM(requests) AS requests')->groupBy('country')->orderByDesc('requests')->get();
        $cities = (clone $base)->selectRaw('country, region, city, SUM(requests) AS requests')->groupBy('country', 'region', 'city')
            ->orderByDesc('requests')->orderBy('country')->orderBy('region')->orderBy('city')->paginate(25, ['*'], 'cities_page')->withQueryString();
        $routes = (clone $base)->selectRaw('route, method, SUM(requests) AS requests')->groupBy('route', 'method')
            ->orderByDesc('requests')->orderBy('route')->orderBy('method')->paginate(25, ['*'], 'routes_page')->withQueryString();
        $visitors = DB::table('access_daily_visitors')->where('day', '>=', $from)->where('day', '<=', now('UTC')->toDateString())->count();
        $geoStatus = $geo->status();

        return view('owner.access', compact('days', 'totals', 'daily', 'countries', 'cities', 'routes', 'visitors', 'geoStatus'));
    }
}
