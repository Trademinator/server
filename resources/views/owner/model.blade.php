<x-owner.layout title="Model validation details">
    <p><a href="{{ route('owner.evaluations.show', $model) }}">View candlestick evaluation and prediction overlays →</a></p>
    <section class="owner-panel"><h2>{{ $report['exchange'] ?? '' }} · {{ $report['symbol'] ?? '' }} · {{ $report['period'] ?? '' }}</h2>
        <dl class="owner-details"><dt>Model UUID</dt><dd><code>{{ $model }}</code></dd><dt>Dataset UUID</dt><dd><code>{{ $report['dataset_id'] ?? 'Unknown' }}</code></dd>
            <dt>Intelligence readiness</dt><dd><x-knn-readiness :report="$report" :coingecko="$coingecko" /></dd><dt>Build result</dt><dd>{{ $report['status'] ?? 'Unknown' }} · {{ $report['reason'] ?? 'Unknown' }}</dd><dt>Knowledge rows</dt><dd>{{ \App\Helpers\Decimal::format($report['knowledge_rows'] ?? 0) }}</dd><dt>Selected K</dt><dd>{{ $report['k'] ?? 'No eligible K' }}</dd><dt>Built at</dt><dd><x-display-time :value="$report['created_at'] ?? null" fallback="Unknown" /></dd>
        </dl>
    </section>
    <section class="owner-panel">
        <h2>Independent Outcome / Action holdout diagnostics</h2>
        @include('owner.partials.action-validation', ['report' => $report])
    </section>
    @foreach(['selection' => 'K selection and chronological validation', 'holdout' => 'Separate holdout evaluation', 'patterns' => 'Pattern intelligence', 'lead_lag' => 'Cross-exchange lead / lag', 'human_guidance' => 'Human Outcome Training source', 'candle_guidance' => 'Human Action Training source'] as $key => $label)
        <section class="owner-panel"><h2>{{ $label }}</h2><pre>{{ json_encode($report[$key] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) }}</pre></section>
    @endforeach
</x-owner.layout>
