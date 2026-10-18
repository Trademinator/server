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
            <h2>Rubix ML research tournament</h2>
            <p class="guide-help">Four separate Rubix classifiers compete on the same chronological sample: Weighted KNN, Classification Tree, Random Forest and Multilayer Perceptron. Research rankings do not select production signals or execute trades. Results require a separate CLI research run.</p>
            @if($tournament)
                @foreach(['outcome' => 'Five-class Outcome', 'action' => 'BUY / HOLD / SELL Action'] as $task => $caption)
                    @php($competition = $tournament['tasks'][$task] ?? [])
                    <h3>{{ $caption }} · research leader: {{ $competition['research_leader'] ?? 'No eligible champion' }}</h3>
                    <p class="guide-help">Training {{ $competition['train_rows'] ?? 0 }} · untouched holdout {{ $competition['holdout_rows'] ?? 0 }} · model ID is unchanged</p>
                    @if(isset($competition['baseline']))
                        <p class="guide-help">Training-only baseline: majority {{ $competition['baseline']['majority_class'] ?? '—' }} ·
                        baseline macro F1 {{ number_format(100*($competition['baseline']['macro_f1'] ?? 0), 1) }}%
                        @if($task === 'action') · dominant directional class {{ $competition['baseline']['directional_class'] ?? '—' }} · directional reference {{ number_format(100*($competition['baseline']['directional_precision'] ?? 0), 1) }}% @endif
                        </p>
                    @endif
                    <div class="review-table-wrap"><table>
                        <thead><tr><th>Rubix candidate</th><th>Status</th><th>{{ $task === 'outcome' ? 'Macro F1' : 'BUY/SELL precision' }}</th><th>Coverage</th><th>Walk-forward mean</th><th>Lowest fold</th><th>Eligibility</th><th>Calibration (5 bins)</th><th>Time</th><th>Memory peak</th></tr></thead>
                        <tbody>
                        @foreach(($competition['candidates'] ?? []) as $name => $candidate)
                            <tr><th>{{ str_replace('_', ' ', ucwords($name, '_')) }}</th>
                                <td>{{ $candidate['status'] }}</td>
                                <td>{{ isset($candidate['score']) ? number_format($candidate['score'] * 100, 1).'%' : '—' }}</td>
                                <td>{{ number_format(($candidate['coverage'] ?? 0) * 100, 1) }}%</td>
                                <td>{{ isset($candidate['walk_forward_mean']) ? number_format(100*$candidate['walk_forward_mean'], 1).'%' : '—' }}</td>
                                <td>{{ isset($candidate['walk_forward_min']) ? number_format(100*$candidate['walk_forward_min'], 1).'%' : '—' }}</td>
                                <td>{{ ($candidate['eligible'] ?? false) ? 'Eligible' : implode(', ', $candidate['failed_gates'] ?? ['Not evaluated']) }}</td>
                                <td>
                                    @if(isset($candidate['calibration']))
                                        @foreach($candidate['calibration'] as $index => $bin)
                                            <span title="Confidence {{ $index*20 }}–{{ ($index+1)*20 }}%">
                                                {{ $index*20 }}–{{ ($index+1)*20 }}%:
                                                {{ $bin['samples'] }} samples,
                                                {{ $bin['accuracy'] !== null ? number_format(100*$bin['accuracy'], 1).'%' : '—' }} accuracy
                                            </span>
                                        @endforeach
                                    @else
                                        —
                                    @endif
                                </td>
                                <td>{{ $candidate['elapsed_ms'] ?? '—' }} ms</td>
                                <td>{{ $candidate['peak_memory_mb'] ?? '—' }} MB</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table></div>
                @endforeach
            @else
                <p>No tournament results for this frozen dataset yet. Run <code>php -d memory_limit=512M artisan trademinator:tournament {{ $report['dataset_id'] ?? 'DATASET_UUID' }}</code> to evaluate challengers.</p>
            @endif
        </section>
        <section class="guide-panel">
            <h2>Historical prediction overlay</h2>
            <p class="guide-help">Green ↑ BUY · Red ↓ SELL · Blue circles Outcome BULL · Orange circles Outcome BEAR. Faded marks are incorrect. Gray marks indicate supported HOLD; abstentions are hidden unless toggled. No execution or profitability is implied.</p>
            <div class="flex flex-wrap gap-4">
                <label for="evaluation-algorithm">Model</label>
                <select id="evaluation-algorithm" data-algorithm>
                    <option value="production">Published KNN</option>
                    @if($tournament)
                        @foreach(['weighted_knn' => 'Rubix weighted KNN', 'decision_tree' => 'Decision Tree', 'random_forest' => 'Random Forest', 'neural_network' => 'Neural Network'] as $name => $label)
                            <option value="{{ $name }}">{{ $label }} (research)</option>
                        @endforeach
                    @endif
                </select>
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
