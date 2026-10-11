            @php($owner = auth()->user()->isOwner())
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                @forelse ($cards as $card)
                    @php($subscription = $card['subscription'])
                    @php($market = $subscription->market)
                    @php($dashboardParams = array_filter(['subscription' => $subscription->getKey(), 'page' => $subscriptions->currentPage(),
                        'q' => $search !== '' ? $search : null, 'scope' => $owner ? $scope : null,
                        'favorites' => $favoritesOnly ? 1 : null], fn ($value) => $value !== null))
                    @php($favoriteParams = array_filter(['subscription' => $subscription->getKey(), 'selected' => $selectedId,
                        'page' => $subscriptions->currentPage(), 'q' => $search !== '' ? $search : null,
                        'scope' => $owner ? $scope : null, 'favorites' => $favoritesOnly ? 1 : null], fn ($value) => $value !== null))
                    <article class="dashboard-market {{ $selectedId === $subscription->getKey() ? 'is-selected' : '' }}">
                        <div class="flex items-start gap-3">
                            <a class="dashboard-market-link min-w-0 flex-1" href="{{ route('dashboard', $dashboardParams) }}#market-detail">
                                <div>
                                    <h3>{{ $market->symbol }}</h3>
                                    <p class="flex items-center gap-2"><span class="dashboard-exchange-logo" aria-hidden="true">@if ($card['logo_url'])<img src="{{ $card['logo_url'] }}" height="24" loading="lazy" decoding="async" referrerpolicy="no-referrer" alt="">@endif<span>{{ mb_strtoupper(mb_substr($market->exchange->name, 0, 1)) }}</span></span>{{ $market->exchange->name }} · {{ $market->feed?->selected_period ?? 'Selecting period' }}</p>
                                    <div class="flex flex-wrap gap-2 mt-2">
                                        @if ($owner)<span class="guide-badge">{{ $card['is_mine'] ? 'Mine' : 'User market' }}</span>
                                        @else<span class="guide-badge">Following</span>@endif
                                    </div>
                                </div>
                                @if ($card['sparkline'])<svg viewBox="0 0 200 48" role="img" aria-label="Recent closed price history for {{ $market->symbol }}" class="dashboard-sparkline"><polyline points="{{ $card['sparkline'] }}" fill="none" stroke="currentColor" stroke-width="2" vector-effect="non-scaling-stroke" /></svg>
                                @else<p class="dashboard-muted my-3">Chart awaiting continuous price history</p>@endif
                                <div class="flex flex-wrap justify-between gap-2"><strong>{{ $card['label'] }}</strong></div>
                                @if ($card['attention'])<p class="dashboard-warning">Collection or history needs attention</p>@endif
                                <p class="guide-help">Last candle closed: <x-display-time :value="$card['chart']['last_closed_at_ms']" unit="milliseconds" /></p>
                                <x-knn-readiness :report="$card['report']" :coingecko="$card['coingecko']" class="mt-3" />
                            </a>
                            <form method="POST" action="{{ route($card['is_favorite'] ? 'dashboard.favorites.destroy' : 'dashboard.favorites.store', $favoriteParams) }}">
                                @csrf
                                @if ($card['is_favorite']) @method('DELETE') @else @method('PUT') @endif
                                <button type="submit" class="dashboard-favourite-button" aria-pressed="{{ $card['is_favorite'] ? 'true' : 'false' }}" title="{{ $card['is_favorite'] ? 'Remove from favourites' : 'Add to favourites' }}">
                                    <span aria-hidden="true">{{ $card['is_favorite'] ? '★' : '☆' }}</span><span class="sr-only">{{ $card['is_favorite'] ? 'Remove '.$market->symbol.' from favourites' : 'Add '.$market->symbol.' to favourites' }}</span>
                                </button>
                            </form>
                        </div>
                    </article>
                @empty
                    @if ($search !== '')<div class="guide-panel md:col-span-2 xl:col-span-3"><p>No markets match “{{ $search }}”. Try another pair, exchange or period.</p></div>
                    @elseif ($favoritesOnly)<div class="guide-panel md:col-span-2 xl:col-span-3"><p>No favourite markets in this scope yet. Use the star on a market to add one.</p></div>
                    @elseif ($owner && $scope === 'all')<div class="guide-panel md:col-span-2 xl:col-span-3"><p>No active market subscriptions exist yet.</p></div>
                    @else<div class="guide-panel md:col-span-2 xl:col-span-3"><h3>Start following a market</h3><p>Subscribe to collect its history and follow its intelligence. You can observe without trading.</p><a class="dashboard-button mt-3" href="{{ route('markets.index') }}">Choose a market</a></div>@endif
                @endforelse
            </div>
            <div class="mt-4" data-market-pagination>{{ $subscriptions->links() }}</div>
        @can('manage-server')
        @php($attentionCards = $cards->where('attention', true))
        @if ($attentionCards->isNotEmpty())
        <details id="attention" class="dashboard-attention guide-panel mt-5" aria-labelledby="attention-title" data-dashboard-attention>
            <summary><h2 id="attention-title">Needs attention</h2></summary>
            <div class="mt-3">
            <p class="guide-help">Collection issues for the displayed markets. Run suggested commands from the server's project directory.</p>
            @foreach ($attentionCards as $card)
                <div class="dashboard-attention-row">
                    <a href="{{ route('dashboard', array_filter(['subscription' => $card['subscription']->getKey(),
                        'scope' => $owner ? $scope : null, 'favorites' => $favoritesOnly ? 1 : null,
                        'q' => $search !== '' ? $search : null], fn ($value) => $value !== null)) }}#market-detail">{{ $card['subscription']->market->exchange->name }} · {{ $card['subscription']->market->symbol }} · {{ $card['subscription']->market->feed?->selected_period ?? 'Period pending' }}</a>
                    @foreach ($card['issues'] as $issue)
                        <div><p>{{ $issue['message'] }} @if(isset($issue['last_closed_at_ms'])) Last valid candle closed at <x-display-time :value="$issue['last_closed_at_ms']" unit="milliseconds" />. @endif</p>
                            @foreach ($issue['commands'] as $command)<pre class="dashboard-command"><code>{{ $command }}</code></pre>@endforeach
                        </div>
                    @endforeach
                </div>
            @endforeach
            </div>
        </details>
        @endif
        @endcan
