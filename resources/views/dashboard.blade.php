<x-layouts.app :title="__('Dashboard')">
    @php
        $number = fn ($value, $digits = 2) => $value === null ? 'Unknown' : \App\Helpers\Decimal::format($value, $digits);
        $owner = auth()->user()->isOwner();
        $ownerViewingAll = $owner && $scope === 'all';
        $attentionCount = $cards->where('attention', true)->count();
    @endphp
    @include('markets.guide-styles')
    @include('markets.intelligence-styles')
    <style>
        @media (max-width: 640px) {
            .dashboard-category-grid-inline { grid-template-columns: 1fr !important; }
        }
    </style>
    <div class="dashboard-page flex flex-col gap-5">
        <header class="dashboard-hero">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div><p class="dashboard-eyebrow">{{ $ownerViewingAll ? 'Server market intelligence' : 'Your market intelligence' }}</p><h1>Dashboard</h1>
                    <p>{{ $ownerViewingAll ? 'Review every active market subscription, understand the evidence, and see what changed.' : 'Follow markets, understand the evidence, and see what changed.' }}</p></div>
                <a class="dashboard-button dashboard-button-light" href="{{ route('markets.index') }}">Manage subscriptions</a>
            </div>
            <p class="dashboard-contract">A subscription lets you follow a market and is required for the Client to trade it. Following a market does not enable trading; the Client decides whether to act.</p>
        </header>
        @if (session('status'))<p class="guide-notice" role="status">{{ session('status') }}</p>@endif
        <div class="grid gap-3 sm:grid-cols-2 {{ auth()->user()->can('manage-server') ? ($attentionCount > 0 ? 'xl:grid-cols-4' : 'xl:grid-cols-3') : 'lg:grid-cols-3' }}" aria-label="Dashboard overview" data-dashboard-overview>
            <a class="dashboard-stat" href="#subscriptions"><span>{{ $ownerViewingAll ? 'Markets followed by users' : 'Markets you follow' }}</span><strong>{{ $totals['followed'] }}</strong><small>Active subscriptions in this scope</small></a>
            <a class="dashboard-stat" href="#subscriptions"><span>Intelligence readiness</span><div class="my-2"><x-knn-readiness :counts="$totals" :total="$totals['followed']" /></div><small>{{ $ownerViewingAll ? 'Across all active subscriptions' : 'Across all markets you follow' }}</small></a>
            @can('manage-server')
            <button type="button" class="dashboard-stat" data-attention-toggle aria-controls="attention" aria-expanded="false" @if ($attentionCount === 0) hidden @endif><span>Needs attention</span><strong data-attention-count>{{ $attentionCount }}</strong><small>Collection or history on this page</small></button>
            @endcan
            <a class="dashboard-stat" href="#changes"><span>New signal changes</span><strong>{{ $changeCount }}</strong><small>Since <x-display-time :value="$since" unit="milliseconds" /></small></a>
        </div>
        <section class="guide-panel" aria-labelledby="conditions-title">
            <div class="flex flex-wrap items-center justify-between gap-3"><h2 id="conditions-title">Market conditions</h2><span class="guide-badge">CoinGecko context</span></div>
            @if ($conditions)
                <div class="grid gap-3 sm:grid-cols-3 mt-3">
                    <div class="dashboard-stat">
                        <span>Global market cap · 24h</span>
                        <strong>{{ $number($conditions['market_change_24h']) }}%</strong>
                        <small>Broad crypto market</small>
                    </div>
                    <div class="dashboard-stat">
                        <span>Bitcoin dominance</span>
                        <strong>{{ $number($conditions['btc_dominance']) }}%</strong>
                        <small>Share of global crypto market cap</small>
                    </div>
                    <div class="dashboard-stat">
                        <span>Global volume · 24h</span>
                        <strong>{{ $number($conditions['volume_usd'] === null ? null : $conditions['volume_usd'] / 1000000000) }} billion USD</strong>
                        <small>Reported global trading volume</small>
                    </div>
                </div>
                <p class="guide-help">Observed <x-display-time :value="$conditions['observed_at_ms']" unit="milliseconds" />. Broad market context; chart prices below come from the selected exchange.</p>
                @if ($conditions['categories'])
                    <div class="grid gap-3 mt-3 dashboard-category-grid-inline" style="grid-template-columns: repeat(5, minmax(0, 1fr));" aria-label="Largest category movements in the available sample">
                        @foreach ($conditions['categories'] as $category)
                            <div class="dashboard-stat">
                                <span>{{ $category['name'] }}</span>
                                <strong>{{ $number($category['change_24h']) }}%</strong>
                                <small>Market cap · 24h</small>
                            </div>
                        @endforeach
                    </div>
                @endif
            @else
                <p>Fresh market context is not available. Your subscribed-market charts and intelligence remain available below.</p>
            @endif
        </section>
        <section id="subscriptions" aria-labelledby="subscriptions-title">
            <div class="flex flex-wrap items-center justify-between gap-3 mb-3"><h2 id="subscriptions-title" class="text-xl font-semibold">{{ $favoritesOnly ? 'Favourite markets' : ($ownerViewingAll ? 'All followed markets' : 'Markets you follow') }}</h2><span class="dashboard-muted">Choose a market to inspect its chart and readiness</span></div>
            <div data-dashboard-markets data-url="{{ route('dashboard') }}" data-selected="{{ $selectedId }}">
                <form method="GET" action="{{ route('dashboard') }}" class="mb-4" data-market-search>
                    <div class="flex flex-wrap items-end gap-3">
                        <div class="flex-1">
                            <label for="market-search" class="block font-semibold mb-2">{{ $ownerViewingAll ? 'Search all markets' : 'Search your markets' }}</label>
                            <input id="market-search" name="q" type="search" maxlength="100" value="{{ $search }}" placeholder="Pair, exchange or period…" class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-slate-900 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100" aria-controls="market-results" autocomplete="off">
                        </div>
                        @if ($owner)
                            <label class="block font-semibold">Scope
                                <select name="scope" class="mt-2 rounded-lg border border-slate-300 bg-white px-3 py-2 text-slate-900 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100">
                                    <option value="all" @selected($scope === 'all')>All markets</option>
                                    <option value="mine" @selected($scope === 'mine')>My markets</option>
                                </select>
                            </label>
                        @endif
                        <label class="flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3 py-2 font-semibold text-slate-900 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100"><input name="favorites" type="checkbox" value="1" @checked($favoritesOnly)> Favourites only</label>
                    </div>
                    <noscript><button type="submit" class="dashboard-button mt-2">Apply filters</button></noscript>
                </form>
                <p class="guide-help mb-3" role="status" aria-live="polite" data-search-status>{{ $subscriptions->total() }} matching markets</p>
                <div id="market-results" data-market-results>@include('dashboard-markets')</div>
            </div>
        </section>
        @if ($details)
            @php($selected = $details['subscription'])
            @php($market = $selected->market)
            @php($progress = $details['progress'])
            @php($chart = $details['chart'])
            <section id="market-detail" class="guide-panel" aria-labelledby="market-title">
                <div class="flex flex-wrap justify-between items-start gap-3"><div><h2 id="market-title">{{ $market->symbol }} · {{ $market->exchange->name }}</h2><p>Closed {{ $chart['period'] ?? 'pending' }} candles · {{ explode('/', $market->symbol)[1] ?? '' }} per {{ explode('/', $market->symbol)[0] }} · <x-timezone-label /></p></div></div>
                <x-market-decision-chart
                    data-dashboard-chart
                    :data-url="route('dashboard.chart', $selected->getKey())"
                    :data-history-url="route('dashboard.chart.history', $selected->getKey())"
                    :data-subscription="$selected->getKey()"
                    :data-chart="json_encode($chart, JSON_THROW_ON_ERROR)"
                    :data-tick-size="$market->tick_size"
                    :aria-label="$market->symbol.' price history with recorded Server decisions, Client activity, and my Action Training labels'"
                    legend="Closed exchange candles with recorded Server decisions and Client reports.">
                    <noscript><p>Enable JavaScript for the interactive chart. Recent candle values and the signal journal remain available.</p></noscript>
                </x-market-decision-chart>
                <p class="guide-help">Server: ↑ BUY · ↓ SELL. HOLD and abstentions are hidden. Optional blue Action proposals and blue Outcome observation lines are disabled by default. Client: C BUY/C SELL decisions and FILL markers include reported fill price. Client reports are shown only when the authenticated user submitted them.</p>
                <p class="guide-help">Server decisions are attached to the evaluated candle; actual recording times remain in the journal. Outcome lines connect observed closes across H later candles after the endpoint closes, not predicted target prices. No past decisions are synthesized.</p>
                <p class="guide-help">TradingView Lightweight Charts™ · <a href="https://www.tradingview.com/" target="_blank" rel="noopener noreferrer">TradingView</a> · Market data collected by Trademinator.</p>
                <details class="mt-3"><summary>Recent closed candle values</summary><div class="dashboard-table-wrap"><table><thead><tr><th>Open time (<x-timezone-label />)</th><th>Open</th><th>High</th><th>Low</th><th>Close</th><th>Volume</th></tr></thead><tbody>
                    @forelse (array_reverse(array_slice($chart['series'], -10)) as $candle)<tr><td><x-display-time :value="$candle['time'] * 1000" unit="milliseconds" /></td><td>{{ $number($candle['open'], 8) }}</td><td>{{ $number($candle['high'], 8) }}</td><td>{{ $number($candle['low'], 8) }}</td><td>{{ $number($candle['close'], 8) }}</td><td>{{ $number($candle['volume'], 8) }}</td></tr>@empty<tr><td colspan="6">No closed candles yet.</td></tr>@endforelse
                </tbody></table></div></details>
                <div class="flex flex-wrap justify-end gap-3 mt-4">
                    @can('train-intelligence')
                        <a class="dashboard-button" href="{{ route('human-training.index', ['exchange' => $market->exchange->class, 'symbol' => $market->symbol, 'period' => $chart['period']]) }}">Train</a>
                    @endcan
                    <a class="dashboard-button" href="{{ route('markets.intelligence', $selected->getKey()) }}">Full intelligence report</a>
                </div>
            </section>
            <section class="guide-panel" aria-labelledby="readiness-title">
                <h2 id="readiness-title">Intelligence readiness · {{ $market->symbol }}</h2>
                <p>{{ $progress['action'] }}</p>
                <div class="grid gap-3 lg:grid-cols-3 mt-4">
                    <div class="dashboard-stat">
                        <h3>1. Usable history</h3>
                        @if ($progress['history'])<x-intelligence-progress :label="$progress['history']['sampled'] ? 'Potential training rows (checked sample)' : 'Potential training rows'" :value="$progress['history']['potential']" :target="$progress['minimum']" native />
                            @if ($progress['history']['sampled'])<p class="guide-help">Checked the latest {{ \App\Helpers\Decimal::format($progress['history']['checked']) }} of {{ \App\Helpers\Decimal::format($progress['history']['closed']) }} rows in the age window. Older eligible rows are also available to training.</p>@endif
                        @else<p>Waiting for a selected candle period and complete features.</p>@endif
                        <p class="guide-help">Estimate before source checks and model-specific exclusions.</p>
                    </div>
                    <div class="dashboard-stat">
                        <h3>2. Model build</h3>
                        <p>{{ $details['report'] ? 'A model build has completed.' : 'No model build recorded yet.' }}</p>
                        @if ($details['report'])<p class="guide-help">Model {{ $details['report']['model_id'] }}</p>@endif
                    </div>
                    <div class="dashboard-stat">
                        <h3>3. Validation</h3>
                        <x-knn-readiness :report="$details['report']" :coingecko="$details['coingecko']" />
                        <p class="guide-help">Green: Outcome KNN. Blue: Action KNN. Yellow: fresh CoinGecko core context (optional 24-hour trends and category data may still be warming up). Each KNN check requires its own current, validated model with a positive scoring weight. Readiness does not guarantee a signal for every candle.</p>
                    </div>
                </div>
                @if ($progress['eta'])<p><strong>Earliest data estimate: <x-display-time :value="$progress['eta']" /></strong></p>@endif
                <p class="guide-help">{{ $progress['eta_note'] }} Validated-model ETA: unknown.</p>
                @if ($progress['issues'])<ul class="list-disc pl-5 mt-3">@foreach ($progress['issues'] as $issue)<li>{{ $issue }}</li>@endforeach</ul>@endif
                @if ($details['signal'] && $details['signal_fresh'])
                    <div class="guide-notice mt-4"><strong>{{ \App\Domain\Intelligence\SignalJournal::label($details['signal']->action, $details['signal']->reason) }}</strong> · {{ \App\Domain\Intelligence\SignalJournal::explain($details['signal']->reason) }}
                        @if (\App\Domain\Intelligence\SignalJournal::hasDecision($details['signal']->action, $details['signal']->reason))<p>Confidence score: {{ $number(($details['signal']->payload['confidence'] ?? 0) * 100, 1) }}% · Effective neighbors: {{ $number($details['signal']->payload['effective_neighbors'] ?? null, 1) }}. Confidence is an evidence score, not a probability of profit.</p>@endif</div>
                @else<p class="guide-notice mt-4">No current recorded signal is available. Collection and the scheduled signal recorder must run before a fresh observation appears.</p>@endif
            </section>
            <section class="guide-panel" aria-labelledby="journal-title"><h2 id="journal-title">Recorded signal journal · {{ $market->symbol }}</h2><p>Latest 20 Server observations with any Client reports linked to the exact immutable signal. No report means Unknown.</p>
                <div class="dashboard-table-wrap"><table><thead><tr><th>Recorded (<x-timezone-label />)</th><th>Server decision</th><th>Explanation and evidence</th><th>Client execution</th></tr></thead><tbody>
                    @forelse ($details['history'] as $signal)
                        @php($reports = $details['client_reports']->get($signal->getKey(), collect()))
                        @php($latestReport = $reports->last())
                        <tr><td><x-display-time :value="$signal->recorded_at_ms" unit="milliseconds" /></td><td>{{ \App\Domain\Intelligence\SignalJournal::label($signal->action, $signal->reason) }}</td><td>{{ \App\Domain\Intelligence\SignalJournal::explain($signal->reason) }}<details><summary>Trace this observation</summary><p>Source candle closed: <x-display-time :value="$signal->decision_at_ms" unit="milliseconds" /><br>Period: {{ $signal->period }}<br>Model: {{ $signal->model_id ?? 'None' }}<br>Signal: {{ $signal->getKey() }}<br>Horizon: {{ $signal->payload['horizon_candles'] ?? 'Unknown' }} candles<br>Confidence score: {{ \App\Domain\Intelligence\SignalJournal::hasDecision($signal->action, $signal->reason) ? $number(($signal->payload['confidence'] ?? 0) * 100, 1).'%' : 'Not supported' }}</p></details></td><td>
                            @if ($latestReport)
                                <strong>{{ strtoupper($latestReport->event) }}@if($latestReport->side) · {{ strtoupper($latestReport->side) }}@endif</strong><br>
                                <span><x-display-time :value="$latestReport->occurred_at_ms" unit="milliseconds" /></span>
                                @if ($latestReport->event === 'fill')<br><span>Price {{ $number($latestReport->price, 8) }} · Qty {{ $number($latestReport->quantity, 8) }}</span>@endif
                                @if ($latestReport->reason)<br><span>{{ $latestReport->reason }}</span>@endif
                                @if ($latestReport->protective)<br><span>Protective / exit report</span>@endif
                                @if ($reports->count() > 1)<details><summary>{{ $reports->count() }} Client reports</summary>@foreach($reports as $report)<p>{{ strtoupper($report->event) }} · <x-display-time :value="$report->occurred_at_ms" unit="milliseconds" />@if($report->price) · {{ $number($report->price, 8) }}@endif</p>@endforeach</details>@endif
                            @else
                                Unknown
                            @endif
                        </td></tr>
                    @empty<tr><td colspan="4">No signals recorded yet. History starts when the signal recorder runs; past decisions are not invented.</td></tr>@endforelse
                </tbody></table></div>
            </section>
        @endif
        <div class="grid gap-5 lg:grid-cols-2">
            <section class="guide-panel" id="changes" aria-labelledby="changes-title"><h2 id="changes-title">Changes since your last visit</h2><p class="guide-help">Since <x-display-time :value="$since" unit="milliseconds" /> · Showing up to 20 changes across active subscriptions.</p>
                @forelse ($timeline as $event)<p class="dashboard-attention-row"><strong>{{ $event->market->symbol }} · {{ $event->market->exchange->name }}</strong><span>{{ \App\Domain\Intelligence\SignalJournal::label($event->action, $event->reason) }} · <x-display-time :value="$event->recorded_at_ms" unit="milliseconds" /></span></p>@empty<p>No new recorded signal changes. Normal collection can continue without a directional signal.</p>@endforelse
            </section>
            <section class="guide-panel" aria-labelledby="coverage-title"><h2 id="coverage-title">Subscription coverage</h2>
                @forelse ($overlap as $asset => $count)<p>{{ $asset }} appears as the base asset in {{ $count }} markets on this page.</p>@empty<p>No repeated base assets among the markets shown.</p>@endforelse
                <p class="guide-help">This describes the markets you follow. Your holdings, allocations, and trading activity are known to the Client.</p>
            </section>
        </div>
        <section class="guide-panel" id="suggestions" aria-labelledby="suggestions-title"><div class="flex flex-wrap justify-between gap-3"><h2 id="suggestions-title">Suggested markets to follow</h2><a href="{{ route('markets.suggestions') }}" class="dashboard-link">Edit preferences</a></div>
            <p>Suggestions combine your saved answers with available market evidence. Review a market before choosing to subscribe.</p>
            <div data-dashboard-suggestions data-url="{{ route('dashboard.suggestions') }}"><p class="guide-help" role="status">Loading your suggestions…</p><a href="{{ route('markets.suggestions', ['show' => 1]) }}">Open suggestions</a></div>
        </section>
    </div>
</x-layouts.app>
