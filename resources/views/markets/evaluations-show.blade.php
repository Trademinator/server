<x-layouts.app title="Model evaluation">
    @include('markets.guide-styles')
    <section class="pair-guide pair-review">
        <header class="guide-hero">
            <p><a href="{{ route($ownerRoute ? 'owner.evaluations.index' : 'human-training.evaluations.index') }}">← Model evaluations</a></p>
            <h1>{{ $report['exchange'] ?? '—' }} · {{ $report['symbol'] ?? '—' }} · {{ $report['period'] ?? '—' }}</h1>
            <p>{{ $report['knn_engine'] ?? 'Legacy model' }} · model {{ $model }} · chronological holdout observations only</p>
        </header>
        @php($a = $report['action']['algorithmic']['holdout'] ?? [])
        @php($o = $report['outcome']['algorithmic']['holdout'] ?? [])
        <div class="guide-grid">
            <section class="guide-panel">
                <h2>Action KNN</h2>
                <p>Directional precision: <strong>{{ isset($a['semantic_precision']) ? number_format(100*$a['semantic_precision'], 2).'%' : '—' }}</strong></p>
                <p>Coverage: {{ isset($a['coverage']) ? number_format(100*$a['coverage'], 2).'%' : '—' }}</p>
                <p>Correct / predictions: {{ $a['correct'] ?? '—' }} / {{ $a['directional'] ?? '—' }}</p>
                <p>Supported HOLD: {{ $a['supported_holds'] ?? '—' }} · Abstained: {{ $a['abstained'] ?? '—' }}</p>
                <p>Readiness: {{ $report['action']['algorithmic']['reason'] ?? 'Unavailable' }}</p>
            </section>
            <section class="guide-panel">
                <h2>Outcome KNN</h2>
                <p>Macro F1: <strong>{{ isset($o['macro_f1']) ? number_format(100*$o['macro_f1'], 2).'%' : '—' }}</strong></p>
                <p>Coverage: {{ isset($o['coverage']) ? number_format(100*$o['coverage'], 2).'%' : '—' }}</p>
                <p>Supported: {{ $o['supported'] ?? '—' }} · Abstained: {{ $o['abstained'] ?? '—' }}</p>
                <p>Readiness: {{ $report['outcome']['algorithmic']['reason'] ?? 'Unavailable' }}</p>
            </section>
        </div>
        <section class="guide-panel">
            <h2>Historical prediction overlay</h2>
            <p class="guide-help">Green ↑ BUY · Red ↓ SELL · Blue circles Outcome BULL · Orange circles Outcome BEAR. Faded marks are incorrect. Gray marks indicate supported HOLD; abstentions are hidden unless toggled. No execution or profitability is implied.</p>
            <div class="flex flex-wrap gap-4">
                <label><input type="checkbox" data-action-toggle checked> Action predictions</label>
                <label><input type="checkbox" data-outcome-toggle checked> Outcome predictions</label>
                <label><input type="checkbox" data-abstain-toggle> Abstentions</label>
            </div>
            <div data-evaluation-chart data-series='@json($series)' class="mt-3">
                <p data-status role="status">Loading historical candles…</p>
                <div data-canvas style="height: 430px; min-width: 0" role="img" aria-label="Candles with historical Action and Outcome KNN predictions"></div>
                <p data-inspect class="guide-help">Hover over a candle to inspect predictions.</p>
            </div>
            @if(empty($series['candles']))<p class="guide-help">This version has no usable held-out candle trace. New model builds populate a bounded trace.</p>@endif
        </section>
        <div class="guide-grid">
            @foreach(['Action' => $a, 'Outcome' => $o] as $label => $holdout)
                <section class="guide-panel">
                    <h2>{{ $label }} confusion matrix</h2>
                    @php($matrix = $holdout['confusion'] ?? [])
                    <div class="review-table-wrap"><table>
                        <thead><tr><th>Actual / Predicted</th>@foreach(array_keys($matrix) as $name)<th>{{ $name }}</th>@endforeach</tr></thead>
                        <tbody>@foreach($matrix as $actual => $predictions)<tr><th>{{ $actual }}</th>@foreach(array_keys($matrix) as $name)<td>{{ $predictions[$name] ?? 0 }}</td>@endforeach</tr>@endforeach</tbody>
                    </table></div>
                    <p class="guide-help">Failed gates: {{ implode(', ', $holdout['failed_gates'] ?? []) ?: 'None recorded' }}</p>
                </section>
            @endforeach
        </div>
    </section>
</x-layouts.app>
