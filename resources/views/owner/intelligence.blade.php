<x-owner.layout title="Global intelligence report">
    @php
        $showHistory = (bool) ($filters['history'] ?? false);
    @endphp
    <section class="owner-panel">
        <form class="owner-filters" method="GET" action="{{ route('owner.intelligence') }}" role="search">
            <label>{{ $showHistory ? 'Search model builds' : 'Search production records' }}<input type="search" name="q" value="{{ $filters['q'] }}" maxlength="120" placeholder="Exchange, pair, period or {{ $showHistory ? 'build' : 'production record' }} reason"></label>
            <label>Versions<select name="history"><option value="0">Current production records</option><option value="1" @selected(request('history') == '1')>All model versions</option></select></label>
            <label>{{ $showHistory ? 'Build result' : 'Production record result' }}<select name="status"><option value="">All results</option><option value="ready" @selected(request('status') === 'ready')>Ready at build</option><option value="abstaining" @selected(request('status') === 'abstaining')>Abstaining</option></select></label>
            <input type="hidden" name="sort" value="{{ $sort }}">
            <input type="hidden" name="direction" value="{{ $direction }}">
            <button type="submit">Filter models</button>
            @if ($filters['q'] !== '')<a href="{{ route('owner.intelligence', array_diff_key($filters, ['q' => true])) }}">Clear search</a>@endif
        </form>
        <p><a href="{{ route('owner.evaluations.index') }}">Visual Outcome / Action evaluations →</a></p>
        <p class="owner-muted">{{ \App\Helpers\Decimal::format($models->total()) }} matching {{ $showHistory ? 'model builds' : 'production records' }}.
            @if (! $showHistory)The table searches, filters and sorts production records. Latest training results are shown alongside them. Markets awaiting publication or their first build are listed below.@endif
        </p>
        <div class="owner-scroll">
            <table class="owner-table owner-intelligence-table">
                <thead>
                    <tr>
                        @foreach (['market' => 'Market', 'reason' => $showHistory ? 'Readiness / build reason' : 'Latest readiness / production reason', 'knowledge_rows' => 'Knowledge rows', 'k' => 'K', 'created_at' => 'Built at'] as $column => $label)
                            @php
                                $nextDirection = $sort === $column ? ($direction === 'asc' ? 'desc' : 'asc') : ($column === 'created_at' ? 'desc' : 'asc');
                                $sortLabel = $column === 'reason' ? 'Build reason'.($showHistory ? '' : ' (production record)') : $label;
                            @endphp
                            <th scope="col" @if ($sort === $column) aria-sort="{{ $direction === 'asc' ? 'ascending' : 'descending' }}" @endif>
                                <a href="{{ route('owner.intelligence', array_replace($filters, ['sort' => $column, 'direction' => $nextDirection])) }}"
                                   aria-label="Sort by {{ $sortLabel }}, {{ $nextDirection === 'asc' ? 'ascending' : 'descending' }}"
                                   title="Sort by {{ $sortLabel }}">
                                    {{ $label }} <span aria-hidden="true">{{ $sort === $column ? ($direction === 'asc' ? '↑' : '↓') : '↕' }}</span>
                                </a>
                            </th>
                        @endforeach
                        <th scope="col">Report</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($models as $model)
                        <tr class="owner-intelligence-model">
                            <td class="owner-intelligence-market-cell"><strong class="owner-intelligence-market-name">{{ $model->summary['exchange'] ?? 'Unknown' }} · {{ $model->summary['symbol'] ?? 'Unknown' }}</strong>
                                <small>{{ $model->summary['period'] ?? 'Unknown' }} · {{ $model->current_id ? 'Current production record' : 'Historical version' }}</small>
                            </td>
                            <td data-label="Readiness">
                                @if (! ($filters['history'] ?? false))
                                    <small>Latest completed build</small>
                                    <x-knn-readiness :report="$model->training_summary ?? $model->summary" :coingecko="$model->coingecko" />
                                    <small>{{ $model->training_summary['reason'] ?? $model->summary['reason'] ?? 'Unknown' }}</small>
                                    @if (($model->training_summary['reason'] ?? null) !== ($model->summary['reason'] ?? null))
                                        <small>Production record reason: {{ $model->summary['reason'] ?? 'Unknown' }}</small>
                                    @endif
                                @else
                                    <x-knn-readiness :report="$model->summary" :coingecko="$model->coingecko" />
                                    <small>{{ $model->summary['reason'] ?? 'Unknown' }}</small>
                                @endif
                            </td>
                            <td data-label="Knowledge rows">{{ \App\Helpers\Decimal::format($model->summary['knowledge_rows'] ?? 0) }}</td>
                            <td data-label="Neighbors">Outcome {{ $model->summary['k'] ?? '—' }}<small>Action {{ $model->summary['action_k'] ?? '—' }}</small></td>
                            <td data-label="Built at"><x-display-time :value="$model->created_at" /></td>
                            <td data-label="Report"><a href="{{ route('owner.intelligence.show', $model->model_id) }}">Validation details</a>
                                @if ($model->training_summary !== null && $model->training_summary['model_id'] !== $model->model_id)
                                    <small><a href="{{ route('owner.intelligence.show', $model->training_summary['model_id']) }}">Latest training details</a></small>
                                @endif
                            </td>
                        </tr>
                        @if ($model->training_summary !== null)
                            <tr class="owner-intelligence-training">
                                <td colspan="6"><x-training-model-status :training-report="$model->training_summary" :production-report="$model->summary" /></td>
                            </tr>
                        @endif
                        @if ($model->waterfall !== null)
                            <tr class="owner-intelligence-waterfall">
                                <td colspan="6">
                                    @include('owner.partials.knowledge-waterfall', [
                                        'waterfall' => $model->waterfall, 'marketId' => $model->knowledge_market_id,
                                    ])
                                </td>
                            </tr>
                        @endif
                        @php($analysis = $model->summary['action_label_analysis'] ?? null)
                        <tr class="owner-intelligence-diagnostics">
                            <td colspan="6">
                                <details class="owner-details">
                                    <summary>Auto-label / d / H / K diagnostics</summary>
                                    @if($analysis)
                                        <div class="owner-scroll">
                                            <table class="owner-table">
                                                <tbody>
                                                    <tr><th scope="row">Action labels</th><td>BUY {{ \App\Helpers\Decimal::format($analysis['action_counts']['buy'] ?? 0) }} · HOLD {{ \App\Helpers\Decimal::format($analysis['action_counts']['hold'] ?? 0) }} · SELL {{ \App\Helpers\Decimal::format($analysis['action_counts']['sell'] ?? 0) }}</td></tr>
                                                    <tr><th scope="row">Candles / continuity</th><td>{{ \App\Helpers\Decimal::format($analysis['candles_examined'] ?? 0) }} candles · {{ \App\Helpers\Decimal::format($analysis['contiguous_runs'] ?? 0) }} contiguous runs · {{ \App\Helpers\Decimal::format($analysis['gaps'] ?? 0) }} gaps</td></tr>
                                                    <tr><th scope="row">d</th><td>{{ \App\Helpers\Decimal::format($analysis['distance_observations'] ?? 0) }} / {{ \App\Helpers\Decimal::format($analysis['minimum_distance_observations'] ?? 30) }} minimum observations · min {{ $analysis['distance_min'] ?? '—' }} · mean {{ isset($analysis['distance_mean']) ? \App\Helpers\Decimal::format($analysis['distance_mean'], 2) : '—' }} · median {{ isset($analysis['distance_median']) ? \App\Helpers\Decimal::format($analysis['distance_median'], 2) : '—' }} · max {{ $analysis['distance_max'] ?? '—' }}</td></tr>
                                                    <tr><th scope="row">d frequencies</th><td>@forelse(($analysis['distance_frequencies'] ?? []) as $distance => $frequency)<code>d={{ $distance }} → {{ $frequency }}</code>@if(! $loop->last) · @endif @empty — @endforelse</td></tr>
                                                    <tr><th scope="row">H</th><td>{{ $analysis['horizon'] ?? 'Unavailable' }} <small>{{ $analysis['status'] ?? 'unknown' }}</small></td></tr>
                                                    <tr><th scope="row">K<sub>O</sub> / K<sub>A</sub></th><td>Outcome {{ $model->summary['k'] ?? '—' }} ({{ $model->summary['outcome']['algorithmic']['selection']['k_min'] ?? '—' }}–{{ $model->summary['outcome']['algorithmic']['selection']['k_max'] ?? '—' }}) · Action {{ $model->summary['action_k'] ?? '—' }} ({{ $model->summary['action']['algorithmic']['selection']['k_min'] ?? '—' }}–{{ $model->summary['action']['algorithmic']['selection']['k_max'] ?? '—' }})</td></tr>
                                                    <tr><th scope="row">History window</th><td>
                                                        @if (($analysis['history_policy'] ?? null) === \App\Domain\Intelligence\KnowledgeWindow::TRAINING_POLICY)
                                                            All available eligible history through <x-display-time :value="$analysis['as_of_ms'] ?? null" unit="milliseconds" />
                                                        @else
                                                            <x-display-time :value="$analysis['from_ms'] ?? null" unit="milliseconds" /> → <x-display-time :value="$analysis['as_of_ms'] ?? null" unit="milliseconds" /> · max {{ $analysis['max_history_days'] ?? '—' }} days
                                                        @endif
                                                    </td></tr>
                                                </tbody>
                                            </table>
                                        </div>
                                    @else
                                        <p class="owner-muted">This model predates persisted auto-label diagnostics. Rebuild it with the current intelligence version.</p>
                                    @endif
                                    <p class="owner-muted"><strong>d</strong> = candle distance between consecutive opposite BUY/SELL pivots inside one contiguous candle run. Distances never cross a gap.</p>
                                    <p class="owner-muted"><strong>H</strong> = round(Σ(d × frequency) / Σfrequency), using every valid d across all available eligible history. At least 30 valid d observations are required.</p>
                                    <p class="owner-muted"><strong>K<sub>O</sub></strong> and <strong>K<sub>A</sub></strong> are tuned independently. K<sub>min</sub> = floor(min effective neighbors) + 1; K<sub>max</sub> = min(configured K cap, training warmup size, available rows). Validation selects the final K inside that range.</p>
                                </details>
                                <details class="owner-details">
                                    <summary>Outcome / Action KNN holdout diagnostics</summary>
                                    @include('owner.partials.action-validation', ['report' => $model->summary])
                                </details>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6">No matching models. Collection and feature generation must precede training.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $models->links() }}
    </section>
    @if ($unpublished->isNotEmpty())
        <section class="owner-panel">
            <h2>Subscribed markets awaiting publication</h2>
            <p class="owner-muted">These markets have completed training, but no production model has been published. Showing {{ $unpublished->count() }} matching markets.</p>
            <div class="owner-untrained-grid">
                @foreach ($unpublished as $feed)
                    <article class="owner-untrained-market">
                        <strong>{{ $feed->market->exchange->class }} · {{ $feed->market->symbol }}</strong>
                        <small>{{ $feed->selected_period }} · Latest completed build</small>
                        <x-knn-readiness :report="$feed->training_report" :coingecko="$feed->coingecko" />
                        <x-training-model-status :training-report="$feed->training_report" />
                        <p><a href="{{ route('owner.intelligence.show', $feed->training_report['model_id']) }}">Latest training details</a></p>
                        @include('owner.partials.knowledge-waterfall', [
                            'waterfall' => $feed->waterfall, 'marketId' => $feed->market_id,
                        ])
                    </article>
                @endforeach
            </div>
        </section>
    @endif
    @if ($untrained->isNotEmpty())
        <section class="owner-panel">
            <h2>Subscribed markets awaiting their first model</h2>
            <p class="owner-muted">Showing the first {{ $untrained->count() }} untrained active markets matching these search terms. First-build stages are never silently omitted.</p>
            <div class="owner-untrained-grid">
                @foreach ($untrained as $feed)
                    <article class="owner-untrained-market">
                        <strong>{{ $feed->market->exchange->class }} · {{ $feed->market->symbol }}</strong>
                        <small>{{ $feed->selected_period }} · Never trained</small>
                        @include('owner.partials.knowledge-waterfall', [
                            'waterfall' => $feed->waterfall, 'marketId' => $feed->market_id,
                        ])
                    </article>
                @endforeach
            </div>
        </section>
    @endif
</x-owner.layout>
