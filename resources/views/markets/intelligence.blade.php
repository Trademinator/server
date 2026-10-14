<x-layouts.app :title="'Intelligence · '.$item->market->symbol">
    @include('markets.guide-styles')
    @include('markets.review-styles')
    @include('markets.intelligence-styles')
    <section class="pair-guide pair-review">
        <nav class="review-topline" aria-label="Intelligence navigation">
            <a href="{{ route('markets.index') }}">← Your markets</a>
            <a href="{{ route('markets.suggestions.review', ['exchange' => $item->market->exchange->class, 'symbol' => $item->market->symbol]) }}">Review pair</a>
        </nav>
        <header class="guide-hero">
            <p class="review-eyebrow">{{ $item->market->exchange->name }} · {{ $period ?? 'Period pending' }}</p>
            <h1 class="review-title">{{ $item->market->symbol }} intelligence</h1>
            <p>Market signals learned from genuinely closed historical candles.</p>
        </header>
        <section class="guide-panel">
            <h2>Latest recorded signal</h2>
            <p class="guide-notice">{{ $explanation }}</p>
            @php
                $displayAction = \App\Domain\Intelligence\SignalJournal::hasDecision($signal['action'], $signal['reason'])
                    ? ($signal['action'] === 'hodl' ? 'HOLD' : strtoupper($signal['action']))
                    : 'WAITING';
                $displayMode = match ($signal['reason']) {
                    'degraded_action_only' => 'degraded mode · Action KNN only',
                    'degraded_outcome_only' => 'degraded mode · Outcome KNN only',
                    default => null,
                };
                $displayGate = null;
                $signalMemory = $signal['scoring']['signal_memory'] ?? null;

                if ($signal['reason'] === 'awaiting_price_displacement' && is_array($signalMemory)) {
                    $rawAction = $signalMemory['raw_action'] ?? null;
                    $rawReason = $signalMemory['raw_reason'] ?? null;

                    if (in_array($rawAction, ['buy', 'hodl', 'sell'], true)) {
                        $displayAction = $rawAction === 'hodl' ? 'HOLD' : strtoupper($rawAction);
                        $displayMode = match ($rawReason) {
                            'degraded_action_only' => 'degraded mode · Action KNN only',
                            'degraded_outcome_only' => 'degraded mode · Outcome KNN only',
                            default => null,
                        };
                        $displayGate = 'awaiting price displacement';
                    }
                }
            @endphp
            <div class="review-metrics">
                <div class="review-metric">Action<strong>{{ $displayAction }}</strong>
                    @if ($displayMode || $displayGate)
                        <small class="intelligence-signal-mode">{{ collect([$displayMode, $displayGate])->filter()->implode(' · ') }}</small>
                    @endif
                </div>
                <div class="review-metric">Confidence<strong>{{ \App\Helpers\Decimal::format($signal['confidence'] * 100, 1) }}%</strong></div>
                <div class="review-metric">Market state<strong>{{ ucwords(str_replace('_', ' ', $signal['regime'] ?? 'neutral')) }}</strong></div>
                <div class="review-metric">Effective neighbors<strong>{{ \App\Helpers\Decimal::format($signal['effective_neighbors'], 1) }}</strong></div>
            </div>
            @if (isset($signal['decision_at_ms']))
                <p>Closed-candle decision time: <x-display-time :value="$signal['decision_at_ms']" unit="milliseconds" /></p>
            @endif
            <p class="guide-help">Signals are calculated by background workers. This page shows a recorded observation only for the selected period and current model; expired directional or Action-only observations are not reused. The CLI computes a new prediction and can differ until the next recorder run.</p>
            @if ($signal['reason'] === 'degraded_action_only')
                <p class="guide-notice">Action-only fallback: Action KNN supplied a supported BUY/HOLD/SELL prediction without Outcome KNN confirmation. Its own confidence and evidence gates apply, followed by the normal Client trading safeguards.</p>
            @endif
            <p class="guide-help">Confidence describes the supported evidence (Action KNN alone in Action-only mode); it is not a calibrated probability of profit. Market state is the supported Outcome class, when available. The Client still applies trading fees, balances and execution rules.</p>
            @if ($progress['evidence_evaluated'])
                <x-intelligence-progress label="Effective neighbors required" :value="$signal['effective_neighbors']" :target="$progress['settings']['min_effective_neighbors']" :decimals="1" />
                <x-intelligence-progress label="Final KNN confidence required" :value="$signal['confidence'] * 100" :target="$progress['settings']['min_confidence'] * 100" :decimals="1" suffix="%" />
                <p class="guide-help">These evidence meters can rise or fall with each market state. They are not training progress. Published confidence remains zero while the model abstains.</p>
            @endif
        </section>
        @include('markets.intelligence-readiness')
        @include('markets.intelligence-lead-lag')
        @if($report)
            <section class="guide-panel"><h2>Outcome + Action scoring</h2>
                <x-knn-readiness :report="$report" :coingecko="$coingecko" />
                <p><strong>Outcome KNN</strong> predicts SUPER BEAR, BEAR, NEUTRAL, BULL or SUPER BULL over the market-derived horizon. <strong>Action KNN</strong> predicts BUY, HOLD or SELL. Human Training adjusts each KNN independently and never replaces its algorithmic training.</p>
                <p class="guide-help">Readiness badges describe training-time model validation, not how many neighbours support this particular candle. Current-candle support and neighbours are shown separately below.</p>
                <div class="review-table-wrap"><table>
                    <thead><tr><th scope="col">KNN</th><th scope="col">Prediction</th><th scope="col">Reason</th><th scope="col">Neighbors</th><th scope="col">Effective neighbors</th><th scope="col">Confidence</th><th scope="col">Algorithmic weight</th><th scope="col">Human weight</th></tr></thead>
                    <tbody>
                        @foreach(['outcome_knn' => 'Outcome KNN', 'action_knn' => 'Action KNN'] as $key => $label)
                            @php $component = $signal[$key] ?? null; @endphp
                            <tr>
                                <th scope="row">{{ $label }}</th>
                                @if (is_array($component))
                                    <td>{{ ($component['reason'] ?? null) === 'supported' ? strtoupper(str_replace('_', ' ', $component[$key === 'outcome_knn' ? 'outcome' : 'action'])) : 'Unavailable' }}</td>
                                    <td>{{ ucwords(str_replace('_', ' ', $component['reason'] ?? 'unknown')) }}</td>
                                    <td>{{ \App\Helpers\Decimal::format($component['neighbors'] ?? 0, 0) }}</td>
                                    <td>{{ \App\Helpers\Decimal::format($component['effective_neighbors'] ?? 0, 1) }}</td>
                                    <td>{{ \App\Helpers\Decimal::format(($component['confidence'] ?? 0) * 100, 1) }}%</td>
                                    <td>{{ \App\Helpers\Decimal::format(($component['sources']['effective_weights']['algorithmic'] ?? 0) * 100, 1) }}%</td>
                                    <td>{{ \App\Helpers\Decimal::format(($component['sources']['effective_weights']['human'] ?? 0) * 100, 1) }}%</td>
                                @else
                                    <td>Not recorded</td><td>—</td><td>—</td><td>—</td><td>—</td><td>—</td><td>—</td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table></div>
                @if(isset($signal['outcome_knn'], $signal['action_knn']))
                    <p>{{ ($signal['scoring']['decision_mode'] ?? null) === 'degraded_action_only' ? 'Action-only fallback result' : 'Decision matrix result' }}: <strong>{{ strtoupper($signal['action'] === 'hodl' ? 'hold' : $signal['action']) }}</strong>.</p>
                @else
                    <p class="guide-help">No current recorded Outcome/Action scoring is available. These are unavailable values, not zero-weight evidence.</p>
                @endif
                @if(isset($report['outcome']['schema_selection']))
                    <p>Outcome input schema: <strong>{{ $report['outcome']['schema_selection']['effective_schema'] }}</strong>
                        (requested: {{ $report['outcome']['schema_selection']['requested_schema'] }}).
                        {{ ucwords(str_replace('_', ' ', $report['outcome']['schema_selection']['reason'])) }}.</p>
                @endif
                <p class="guide-help">Human influence follows W_H = min(60%, 60% × √(N_H / 750)). An unavailable human source contributes zero. With both KNNs supported, the 3×5 decision matrix allows Action BUY with Neutral/Bull/Super Bull, but blocks BUY with Bear/Super Bear. If Outcome is unavailable, a supported Action KNN BUY/HOLD/SELL remains actionable on its own evidence.</p>
                @can('train-intelligence')<a href="{{ route('human-training.index', ['exchange' => $item->market->exchange->class, 'symbol' => $item->market->symbol, 'period' => $period]) }}">Open Human Training</a>@endcan
            </section>
        @endif
        <section class="guide-panel">
            <h2>Emerging patterns</h2>
            @if ($signal['patterns'] === [])
                <p>{{ ($signal['patterns_evaluated'] ?? false) ? 'No supported partial pattern is present in the latest closed candles.' : 'Pattern analysis is not available yet.' }}</p>
            @else
                <div class="review-table-wrap">
                    <table>
                        <thead><tr><th scope="col">Pattern</th><th scope="col">Progress</th><th scope="col">Completion probability</th></tr></thead>
                        <tbody>
                        @foreach ($signal['patterns'] as $pattern)
                            <tr>
                                <td>{{ ucwords(str_replace('_', ' ', $pattern['type'])) }}</td>
                                <td><x-intelligence-progress label="Pattern candles" :value="$pattern['stage']" :target="$pattern['length']" /></td>
                                <td>{{ $pattern['completion_probability'] === null ? 'Insufficient validated history' : \App\Helpers\Decimal::format($pattern['completion_probability'] * 100, 1).'%' }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="guide-help">Completion means the remaining candles meet the stated pattern definition. It does not establish a profitable trade.</p>
            @endif
        </section>
        @if ($report)
            <section class="guide-panel">
                <h2>Model validation</h2>
                <dl>
                    <dt>Intelligence readiness</dt><dd><x-knn-readiness :report="$report" :coingecko="$coingecko" /></dd>
                    <dt>Outcome K</dt><dd>{{ $report['k'] ?? 'No eligible value' }}</dd>
                    <dt>Action K</dt><dd>{{ $report['action_k'] ?? 'No eligible value' }}</dd>
                    <dt>Shared knowledge rows</dt><dd>{{ \App\Helpers\Decimal::format($report['knowledge_rows']) }} retained examples</dd>
                    @if (isset($report['training_data']['window']))
                        <dt>History window</dt><dd>{{ \App\Helpers\Decimal::format($report['training_data']['window']['days']) }} days, starting <x-display-time :value="$report['training_data']['window']['from_ms']" unit="milliseconds" /></dd>
                    @endif
                    <dt>Training cutoff</dt><dd><x-display-time :value="$report['trained_as_of_ms']" unit="milliseconds" /></dd>
                    @if ($report['holdout'])
                        <dt>Outcome macro F1</dt><dd>{{ \App\Helpers\Decimal::format(($report['holdout']['macro_f1'] ?? 0) * 100, 1) }}%</dd>
                        <dt>Outcome accuracy</dt><dd>{{ \App\Helpers\Decimal::format(($report['holdout']['accuracy'] ?? 0) * 100, 1) }}%</dd>
                        <dt>Outcome coverage</dt><dd>{{ \App\Helpers\Decimal::format(($report['holdout']['coverage'] ?? 0) * 100, 1) }}%</dd>
                    @endif
                </dl>
                <p class="guide-help">New builds retain every eligible example within the configured history window. The validation minimum does not cap the knowledge pool.</p>
                @if ($progress['source'])
                    <p>Dataset schema: <strong>{{ $progress['source']['schema'] }}</strong>. Labeled source rows: <strong>{{ isset($progress['source']['source_rows']) ? \App\Helpers\Decimal::format($progress['source']['source_rows']) : 'Not recorded' }}</strong>.</p>
                    @if (isset($progress['source']['usable_rows']))
                        <x-intelligence-progress label="Usable KNN history at last training" :value="$progress['source']['usable_rows']" :target="$progress['minimum']" native />
                    @else
                        <p>Usable history after pattern exclusions was not recorded by this older model. Rebuild once to see the exact count.</p>
                    @endif
                    @if (($progress['source']['pattern_excluded_rows'] ?? 0) > 0)
                        <p>{{ \App\Helpers\Decimal::format($progress['source']['pattern_excluded_rows']) }} earlier rows were excluded to keep pattern predictions chronological.</p>
                    @endif
                    @if (($progress['source']['lead_lag_excluded_rows'] ?? 0) > 0)
                        <p>{{ \App\Helpers\Decimal::format($progress['source']['lead_lag_excluded_rows']) }} earlier rows were excluded so lead/lag evidence was validated before every downstream training decision.</p>
                    @endif
                    <p class="guide-help">With these model settings, {{ \App\Helpers\Decimal::format($progress['minimum']) }} contiguous, complete, usable rows permit the minimum validation sample; {{ \App\Helpers\Decimal::format($progress['full_fold_minimum']) }} permit a full tuning block. These estimates include label purging and the separate 20% holdout. Validation must still pass.</p>
                    @if (array_sum($progress['source']['skipped'] ?? []) > 0)
                        <details><summary>Rows excluded from the last dataset</summary>
                            <ul class="intelligence-issues">
                                @foreach ($progress['source']['skipped'] as $reason => $count)
                                    @if ($count > 0)
                                        <li>{{ ucwords(str_replace('_', ' ', $reason)) }}: {{ \App\Helpers\Decimal::format($count) }}</li>
                                    @endif
                                @endforeach
                            </ul>
                        </details>
                    @endif
                @endif
                <h3>Outcome K selection requirements</h3>
                @if ($progress['tuning'])
                    <p>{{ $report['k'] === null ? 'Closest candidate by number of passed checks' : 'Selected candidate' }}: K = {{ $progress['tuning']['k'] }}. All checks must pass for the same candidate.</p>
                    @include('markets.intelligence-gates', ['gates' => $progress['tuning']['gates']])
                @else
                    <p>No tuning results are available yet.</p>
                @endif
                <h3>Outcome separate later-period validation</h3>
                @if ($progress['holdout'])
                    @include('markets.intelligence-gates', ['gates' => $progress['holdout']])
                @else
                    <p>Not evaluated: K selection must pass first.</p>
                @endif
                @if (($report['patterns'] ?? []) !== [])
                    <h3>Pattern training history</h3>
                    @foreach ($report['patterns'] as $type => $patternReport)
                        <x-intelligence-progress :label="ucwords(str_replace('_', ' ', $type)).' samples'" :value="$patternReport['samples']" :target="$progress['pattern_minimum']" />
                        <p class="guide-help">{{ match ($patternReport['status']) {
                            'validated' => 'Validated. A calibrated pattern model is available.',
                            'insufficient_samples' => 'More examples of this pattern are needed.',
                            'insufficient_chronological_classes' => 'The chronological blocks need enough rows and both completed and failed examples.',
                            'no_improvement_over_prior' => 'Validation did not improve on the prior baseline. More samples do not guarantee a passing model.',
                            default => 'Pattern validation is pending.',
                        } }}</p>
                    @endforeach
                @endif
                <p class="guide-help">K is tuned on earlier chronological folds. These later-period results come from a separate held-out block. Models retrain weekly, after successful backfill, and daily for eligible overlapping markets when lead/lag refresh is enabled.</p>
            </section>
            @if (auth()->user()->isOwner() && isset($report['build_performance']))
                @php
                    $performance = $report['build_performance'];
                    $stageLabels = [
                        'dataset_ms' => 'Dataset construction / loading',
                        'patterns_ms' => 'Pattern models',
                        'lead_lag_ms' => 'Lead / lag',
                        'knn_tuning_ms' => 'KNN tuning',
                        'holdout_ms' => 'Holdout validation',
                        'human_guidance_ms' => 'Human Outcome Training',
                        'candle_guidance_ms' => 'Human Action Training',
                        'persistence_ms' => 'Model persistence',
                    ];
                @endphp
                <section class="guide-panel">
                    <details>
                        <summary><strong>Build performance</strong></summary>
                        <dl>
                            @if (! empty($performance['started_at']))
                                <dt>Last model build</dt><dd><x-display-time :value="$performance['started_at']" /></dd>
                            @endif
                            <dt>Total intelligence build</dt><dd>{{ \App\Helpers\Decimal::format(($performance['total_ms'] ?? 0) / 1000, 2) }} s</dd>
                            @foreach ($stageLabels as $key => $label)
                                @if (isset($performance['stages'][$key]))
                                    <dt>{{ $label }}</dt><dd>{{ \App\Helpers\Decimal::format($performance['stages'][$key] / 1000, 2) }} s</dd>
                                @endif
                            @endforeach
                            @if (isset($performance['feature_replay']))
                                <dt>Feature replay</dt><dd>{{ \App\Helpers\Decimal::format(($performance['feature_replay']['duration_ms'] ?? 0) / 1000, 2) }} s</dd>
                                <dt>Ticker rows processed</dt><dd>{{ \App\Helpers\Decimal::format($performance['feature_replay']['rows_processed'] ?? 0) }}</dd>
                                <dt>Replay chunks</dt><dd>{{ \App\Helpers\Decimal::format($performance['feature_replay']['chunks'] ?? 0) }}</dd>
                                <dt>Started from checkpoint</dt><dd>{{ ($performance['feature_replay']['checkpoint_used'] ?? false) ? 'Yes' : 'No' }}</dd>
                            @endif
                        </dl>
                        <p class="guide-help">OWNER-only diagnostics for the most recently published model. Timings are persisted in the model report and also emitted to the action/syslog stream.</p>
                    </details>
                </section>
            @endif
        @endif
    </section>
</x-layouts.app>
