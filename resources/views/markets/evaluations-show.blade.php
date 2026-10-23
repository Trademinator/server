<x-layouts.app title="Model evaluation">
    @include('markets.guide-styles')
    <style>
        .evaluation-overlay-color { font-weight:800; }
        .evaluation-overlay-green { color:#16a34a; }
        .evaluation-overlay-red { color:#ef4444; }
        .evaluation-overlay-yellow { color:#eab308; }
        .evaluation-overlay-gray { color:#94a3b8; }
        .evaluation-abstention-option { border-left:1px solid #94a3b8; padding-left:14px; margin-left:6px; }
        .evaluation-candidates { display:grid; gap:14px; margin:16px 0 24px; }
        .evaluation-candidate { border:1px solid #94a3b8; border-radius:12px; overflow:hidden; min-width:0; }
        .dark .evaluation-candidate { border-color:#475569; }
        .evaluation-candidate-row { display:flex; align-items:flex-start; flex-wrap:wrap; gap:8px 22px; padding:11px 15px; min-width:0; }
        .evaluation-candidate-row + .evaluation-candidate-row { border-top:1px solid #d5deea; }
        .dark .evaluation-candidate-row + .evaluation-candidate-row { border-color:#334155; }
        .evaluation-candidate-heading { align-items:center; justify-content:space-between; background:#edf3fb; }
        .dark .evaluation-candidate-heading { background:#223047; }
        .evaluation-candidate-rank { display:inline-block; min-width:2.2rem; margin-right:10px; font-weight:800; color:#475569; }
        .dark .evaluation-candidate-rank { color:#cbd5e1; }
        .evaluation-candidate-name { color:#16803a; font-size:1.1rem; font-weight:800; }
        .dark .evaluation-candidate-name { color:#4ade80; }
        .evaluation-candidate-status.is-evaluated { color:#15803d; font-weight:700; }
        .dark .evaluation-candidate-status.is-evaluated { color:#4ade80; }
        .evaluation-candidate-status.is-unavailable { color:#b91c1c; font-weight:700; }
        .dark .evaluation-candidate-status.is-unavailable { color:#f87171; }
        .evaluation-candidate-trophy { color:#d9a500; font-size:1.25rem; vertical-align:middle; margin-left:9px; }
        .evaluation-candidate-stat { display:flex; flex-direction:column; gap:2px; min-width:100px; flex:1 1 140px; }
        .evaluation-candidate-stat > span:first-child { font-size:.8rem; opacity:.75; }
        .evaluation-candidate-stat > strong { font-size:1rem; font-variant-numeric:tabular-nums; }
        .evaluation-candidate-detail { width:100%; overflow-wrap:anywhere; }
        .evaluation-candidate-detail > strong { display:block; margin-bottom:4px; }
        .evaluation-calibration { display:grid; grid-template-columns:repeat(5,minmax(0,1fr)); gap:8px; margin-top:8px; }
        .evaluation-calibration-bin { padding:9px; border-radius:7px; background:#edf3fb; min-width:0; font-size:.86rem; }
        .dark .evaluation-calibration-bin { background:#263348; }
        .evaluation-calibration-bin strong, .evaluation-calibration-bin span { display:block; }
        @media(max-width:750px) {
            .evaluation-candidate-row { padding:12px; gap:10px; }
            .evaluation-candidate-stat { flex-basis:calc(50% - 10px); min-width:0; }
            .evaluation-calibration { grid-template-columns:repeat(2,minmax(0,1fr)); }
        }
    </style>
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
                    <p class="guide-help">Ranked by eligibility first, then walk-forward mean, lowest fold and coverage. A research champion must also pass final holdout confirmation; an ineligible candidate can never become champion.</p>
                    <div class="evaluation-candidates">
                        @foreach(($competition['ranked_candidates'] ?? $competition['candidates'] ?? []) as $name => $candidate)
                            <section class="evaluation-candidate" aria-label="{{ str_replace('_', ' ', ucwords($name, '_')) }} evaluation">
                                {{-- Row 1: algorithm identity, status and winner --}}
                                <div class="evaluation-candidate-row evaluation-candidate-heading">
                                    <div>
                                        <span class="evaluation-candidate-rank" aria-label="Research rank {{ $loop->iteration }}">#{{ $loop->iteration }}</span>
                                        <strong class="evaluation-candidate-name">{{ str_replace('_', ' ', ucwords($name, '_')) }}</strong>
                                        @if(($competition['research_leader'] ?? null) === $name)
                                            <span class="evaluation-candidate-trophy" role="img" aria-label="Research champion" title="Research champion">🏆</span>
                                        @endif
                                    </div>
                                    <span class="evaluation-candidate-status {{ ($candidate['status'] ?? '') === 'evaluated' ? 'is-evaluated' : 'is-unavailable' }}">
                                        {{ ucfirst(str_replace('_', ' ', $candidate['status'] ?? 'unavailable')) }}
                                    </span>
                                </div>
                                {{-- Row 2: scoring --}}
                                <div class="evaluation-candidate-row">
                                    <div class="evaluation-candidate-stat"><span>{{ $task === 'outcome' ? 'Macro F1' : 'BUY/SELL precision' }}</span><strong>{{ isset($candidate['score']) ? number_format(100*$candidate['score'], 1).'%' : '—' }}</strong></div>
                                    <div class="evaluation-candidate-stat"><span>Coverage</span><strong>{{ isset($candidate['coverage']) ? number_format(100*$candidate['coverage'], 1).'%' : '—' }}</strong></div>
                                    <div class="evaluation-candidate-stat"><span>Walk-forward mean</span><strong>{{ isset($candidate['walk_forward_mean']) ? number_format(100*$candidate['walk_forward_mean'], 1).'%' : '—' }}</strong></div>
                                    <div class="evaluation-candidate-stat"><span>Lowest fold</span><strong>{{ isset($candidate['walk_forward_min']) ? number_format(100*$candidate['walk_forward_min'], 1).'%' : '—' }}</strong></div>
                                </div>
                                {{-- Row 3: eligibility and baseline --}}
                                <div class="evaluation-candidate-row">
                                    <div class="evaluation-candidate-stat">
                                        <span>Eligibility</span>
                                        <strong>{{ ($candidate['eligible'] ?? false) ? 'Eligible' : 'Not eligible' }}</strong>
                                    </div>
                                    <div class="evaluation-candidate-stat">
                                        <span>Baseline Δ</span>
                                        <strong>{{ isset($candidate['baseline_margin']) ? number_format(100*$candidate['baseline_margin'], 1).' pp' : '—' }}</strong>
                                    </div>
                                    @if(!empty($candidate['failed_gates']))
                                        <div class="evaluation-candidate-detail"><strong>Failed gates</strong>{{ implode(', ', array_map(fn ($gate) => str_replace('_', ' ', $gate), $candidate['failed_gates'])) }}</div>
                                    @endif
                                </div>
                                {{-- Row 4: separate calibration grid --}}
                                <div class="evaluation-candidate-row">
                                    <div class="evaluation-candidate-detail">
                                        <strong>Calibration (5 confidence bins)</strong>
                                        @if(isset($candidate['calibration']))
                                            <div class="evaluation-calibration">
                                                @foreach($candidate['calibration'] as $index => $bin)
                                                    <div class="evaluation-calibration-bin">
                                                        <strong>{{ $index*20 }}–{{ ($index+1)*20 }}%</strong>
                                                        <span>{{ $bin['samples'] }} samples</span>
                                                        <span>{{ $bin['accuracy'] !== null ? number_format(100*$bin['accuracy'], 1).'%' : '—' }} accuracy</span>
                                                    </div>
                                                @endforeach
                                            </div>
                                        @else
                                            <span>Not available</span>
                                        @endif
                                    </div>
                                </div>
                                {{-- Row 5: execution resources --}}
                                <div class="evaluation-candidate-row">
                                    <div class="evaluation-candidate-stat"><span>Time</span><strong>{{ isset($candidate['elapsed_ms']) ? $candidate['elapsed_ms'].' ms' : '—' }}</strong></div>
                                    <div class="evaluation-candidate-stat"><span>Peak memory</span><strong>{{ isset($candidate['peak_memory_mb']) ? $candidate['peak_memory_mb'].' MB' : '—' }}</strong></div>
                                </div>
                            </section>
                        @endforeach
                    </div>
                @endforeach
            @else
                <p>No tournament results for this frozen dataset yet. Run <code>php -d memory_limit=512M artisan trademinator:tournament {{ $report['dataset_id'] ?? 'DATASET_UUID' }}</code> to evaluate challengers.</p>
            @endif
        </section>
        @php($humanBundles = ['outcome' => ($report['outcome']['human'] ?? []), 'action' => ($report['action']['human'] ?? [])])
        @if(collect($humanBundles)->contains(fn ($bundle) => !empty($bundle)))
            <section class="guide-panel">
                <h2>Human-trained models</h2>
                <p class="guide-help">Human KNN evaluations are separate from the Rubix research tournament. Only metrics recorded in the model report are shown.</p>
                <div class="evaluation-candidates">
                    @foreach($humanBundles as $task => $human)
                        @continue(empty($human))
                        @php($metrics = $human['holdout'] ?? $human['tuning'] ?? [])
                        <section class="evaluation-candidate" aria-label="Human-trained {{ ucfirst($task) }} evaluation">
                            <div class="evaluation-candidate-row evaluation-candidate-heading">
                                <strong class="evaluation-candidate-name">Human {{ ucfirst($task) }} KNN</strong>
                                <span class="evaluation-candidate-status {{ in_array(($human['status'] ?? ''), ['validated', 'ready'], true) ? 'is-evaluated' : 'is-unavailable' }}">
                                    {{ ucfirst(str_replace('_', ' ', $human['status'] ?? 'unavailable')) }}
                                </span>
                            </div>
                            <div class="evaluation-candidate-row">
                                <div class="evaluation-candidate-stat"><span>{{ $task === 'outcome' ? 'Macro F1' : 'Directional precision' }}</span><strong>{{ isset($metrics[$task === 'outcome' ? 'macro_f1' : 'semantic_precision']) ? number_format(100*$metrics[$task === 'outcome' ? 'macro_f1' : 'semantic_precision'], 1).'%' : '—' }}</strong></div>
                                <div class="evaluation-candidate-stat"><span>Coverage</span><strong>{{ isset($metrics['coverage']) ? number_format(100*$metrics['coverage'], 1).'%' : '—' }}</strong></div>
                                <div class="evaluation-candidate-stat"><span>Walk-forward</span><strong>—</strong></div>
                                <div class="evaluation-candidate-stat"><span>Lowest fold</span><strong>—</strong></div>
                            </div>
                            <div class="evaluation-candidate-row">
                                <div class="evaluation-candidate-stat"><span>Eligibility</span><strong>{{ ($metrics['eligible'] ?? $human['influence'] ?? false) ? 'Eligible' : 'Not eligible' }}</strong></div>
                                <div class="evaluation-candidate-stat"><span>Baseline lift</span><strong>{{ isset($metrics['precision_lift_over_baseline']) ? number_format(100*$metrics['precision_lift_over_baseline'], 1).' pp' : '—' }}</strong></div>
                                @if(!empty($metrics['failed_gates']))
                                    <div class="evaluation-candidate-detail"><strong>Failed gates</strong>{{ implode(', ', array_map(fn ($gate) => str_replace('_', ' ', $gate), $metrics['failed_gates'])) }}</div>
                                @endif
                            </div>
                            <div class="evaluation-candidate-row"><div class="evaluation-candidate-detail"><strong>Calibration</strong>Not recorded in the human-model report</div></div>
                            <div class="evaluation-candidate-row">
                                <div class="evaluation-candidate-stat"><span>Time</span><strong>—</strong></div>
                                <div class="evaluation-candidate-stat"><span>Peak memory</span><strong>—</strong></div>
                                <div class="evaluation-candidate-stat"><span>Training samples</span><strong>{{ $human['samples'] ?? '—' }}</strong></div>
                            </div>
                        </section>
                    @endforeach
                </div>
            </section>
        @endif
        <section class="guide-panel">
            <h2>Live research shadow evaluation</h2>
            <p class="guide-help">Shadow predictions are research-only and never sent to the Client. Scoring waits for independently finalized labels in a newer frozen dataset.</p>
            @forelse(($shadowSummary ?? []) as $shadow)
                <p>{{ ucfirst($shadow['task']) }} · {{ str_replace('_', ' ', $shadow['algorithm']) }}:
                    {{ $shadow['predictions'] }} recorded predictions,
                    {{ $shadow['scored'] }} scored,
                    {{ $shadow['scored'] ? number_format(100*$shadow['correct']/$shadow['scored'], 1).'%' : '—' }} correct among scored.
                    @if($shadow['predictions'] > $shadow['scored'])
                        Awaiting finalized labels: {{ $shadow['predictions'] - $shadow['scored'] }}.
                    @endif
                </p>
            @empty
                <p>No published shadow challengers for this frozen dataset yet.</p>
            @endforelse
        </section>
        <section class="guide-panel">
            <h2>Historical prediction overlay</h2>
            <p class="guide-help">
                Action markers: <strong class="evaluation-overlay-color evaluation-overlay-green">Green</strong> ↑ BUY when correct ·
                <strong class="evaluation-overlay-color evaluation-overlay-red">Red</strong> ↓ SELL when correct ·
                <strong class="evaluation-overlay-color evaluation-overlay-yellow">Yellow</strong> ↑/↓ means the predicted BUY or SELL was incorrect (disagrees with the historical label).
                <strong class="evaluation-overlay-color evaluation-overlay-gray">Gray</strong> indicates supported HOLD or a displayed abstention.
                Outcome predictions use <strong class="evaluation-overlay-color evaluation-overlay-green">Green</strong> (BULL),
                <strong class="evaluation-overlay-color evaluation-overlay-red">Red</strong> (BEAR), or
                <strong class="evaluation-overlay-color evaluation-overlay-gray">Gray</strong> (NEUTRAL) close-to-close lines across H candles.
                Lines show observed price movement, not predicted targets; subtle slopes retain labels.
                Abstentions are hidden unless enabled. No execution or profitability is implied.
            </p>
            <div class="flex flex-wrap gap-4">
                <label for="evaluation-algorithm">Model</label>
                <select id="evaluation-algorithm" data-algorithm>
                    <option value="production">Current Champion ({{ str_contains((string) ($report['knn_engine'] ?? ''), 'weighted') ? 'Weighted KNN' : ($report['knn_engine'] ?? 'KNN') }})</option>
                    @if($tournament)
                        @foreach(['weighted_knn' => 'Weighted KNN', 'decision_tree' => 'Decision Tree', 'random_forest' => 'Random Forest', 'neural_network' => 'Neural Network'] as $name => $label)
                            <option value="{{ $name }}">{{ $label }} (research)</option>
                        @endforeach
                    @endif
                </select>
                <label><input type="checkbox" data-action-toggle checked> Action predictions</label>
                <label><input type="checkbox" data-outcome-toggle checked> Outcome predictions</label>
                <label class="evaluation-abstention-option"><input type="checkbox" data-abstain-toggle> Abstentions</label>
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
