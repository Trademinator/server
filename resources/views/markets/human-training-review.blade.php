<x-layouts.app title="Outcome Training snapshot">
    @include('markets.guide-styles')
    @include('markets.review-styles')
    <style>
        .human-labels { display:grid; grid-template-columns:repeat(auto-fit,minmax(135px,1fr)); gap:12px; margin:14px 0; }
        .human-labels label { display:flex; align-items:center; gap:8px; padding:12px; border:1px solid #94a3b8; border-radius:8px; cursor:pointer; }
        .pair-guide .human-labels input[type=radio] { width:20px; height:20px; min-height:20px; margin:0; padding:0; }
        .pair-guide textarea { display:block; width:100%; padding:10px; border:2px solid #94a3b8; border-radius:8px; background:white; color:#172033; }
        .human-training-chart-frame { position:relative; overflow:hidden; background:#fff; }
        .dark .human-training-chart-frame { background:#111827; }
        .human-training-chart-canvas { position:absolute; inset:0; z-index:1; }
        .human-training-indicators { display:grid; grid-template-columns:repeat(auto-fit,minmax(min(100%,340px),1fr)); gap:12px; }
        .human-training-assessment-band { position:absolute; z-index:0; top:0; bottom:0; pointer-events:none; background:rgba(245,158,11,.22); border-inline:1px solid rgba(217,119,6,.42); }
        .dark .human-training-assessment-band { background:rgba(251,191,36,.20); border-inline-color:rgba(251,191,36,.48); }
        .human-training-assessment-label { position:absolute; z-index:2; top:8px; transform:translateX(-50%); pointer-events:none; white-space:nowrap; padding:3px 7px; border-radius:6px; background:rgba(146,64,14,.92); color:#fff7ed; font-size:.75rem; font-weight:750; line-height:1.2; box-shadow:0 1px 3px rgba(0,0,0,.25); }
        .human-training-close-change { position:absolute; z-index:4; transform:translateX(-50%); pointer-events:none; border-radius:6px; background:#172c43; color:white; font-weight:750; font-size:.84rem; padding:4px 8px; font-variant-numeric:tabular-nums; white-space:nowrap; }
        .human-training-close-change[data-direction="up"] { background:#087b6b; }
        .human-training-close-change[data-direction="down"] { background:#a5273b; }
        .human-training-close-change[hidden] { display:none; }
        .human-training-future-band { position:absolute; z-index:0; top:0; bottom:0; pointer-events:none; background:rgba(148,163,184,.24); border-inline:1px solid rgba(100,116,139,.5); }
        .human-training-future-label { position:absolute; z-index:2; top:38px; transform:translateX(-50%); pointer-events:none; white-space:nowrap; padding:3px 7px; border-radius:6px; background:#64748b; color:white; font-size:.75rem; font-weight:750; }
        .human-training-assessment-band[hidden], .human-training-assessment-label[hidden], .human-training-future-band[hidden], .human-training-future-label[hidden] { display:none; }
    </style>
    <section class="pair-guide pair-review">
        <nav><a href="{{ route('human-training.index') }}">← Human training</a></nav>
        <header class="guide-hero"><p class="review-eyebrow">Outcome Training · {{ $snapshot['exchange'] }} · {{ $snapshot['symbol'] }} · {{ $snapshot['period'] }}</p>
            <h1>Outcome Training</h1><p>Evaluate the market outcome over the next {{ $snapshot['horizon_candles'] }} candles from <x-display-time :value="$snapshot['decision_at_ms']" unit="milliseconds" />.</p></header>
        @if(session('status'))<p class="guide-notice" role="status">{{ session('status') }}</p>@endif
        @if($errors->any())<div class="guide-notice guide-error" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <section class="guide-panel"><h2>Closed candles at the decision time</h2>
            <p class="guide-notice">The yellow candle is the evaluation point. The grey candle is H periods later, when that candle is available. This is a retrospective outcome assessment; it is not a prediction made at the original decision time.</p>
            @if($snapshot['gaps'])<p class="guide-notice guide-error">{{ $snapshot['gaps'] }} gaps in history. Missing candles are not filled.</p>@endif
            <div data-human-training-chart data-snapshot="{{ json_encode(['series' => $snapshot['series'], 'decision_at_ms' => $snapshot['decision_at_ms'], 'label' => $review->label, 'horizon_candles' => $snapshot['horizon_candles'], 'future_candle' => $futureCandle, 'future_series' => $futureSeries, 'after_series' => $afterSeries, 'machine_outcome' => $machineOutcome], JSON_THROW_ON_ERROR) }}">
                <p class="review-legend" data-legend>Inspect a candle for its OHLC prices and volume.</p>
                <div class="flex flex-wrap gap-3 mb-3" role="group" aria-label="Outcome overlays">
                    <label><input type="radio" name="outcome-overlay" value="human" checked data-outcome-overlay> Human</label>
                    <label><input type="radio" name="outcome-overlay" value="computer" data-outcome-overlay> Computer</label>
                    <label><input type="radio" name="outcome-overlay" value="both" data-outcome-overlay> Both</label>
                </div>
                <div class="review-chart human-training-chart-frame" data-chart-frame role="img" aria-label="Outcome chart with highlighted decision and future H candles">
                    <div class="human-training-assessment-band" data-assessment-band hidden aria-hidden="true"></div>
                    <div class="human-training-future-band" data-future-band hidden aria-hidden="true"></div>
                    <div class="human-training-chart-canvas" data-canvas></div>
                    <span class="human-training-future-label" data-future-label hidden aria-hidden="true">H</span>
                    <span class="human-training-close-change" data-close-change hidden role="note" aria-label="Close-to-close percentage change"></span>
                    <span class="human-training-assessment-label" data-assessment-label hidden aria-hidden="true">Assess from here</span>
                </div>
                <button type="button" class="review-control" data-fit>Fit all candles</button>
                <p class="guide-help" data-status role="status">Loading the frozen chart…</p>
                <noscript><p>The interactive chart requires JavaScript. Candle values and indicators remain available below.</p></noscript>
            </div>
            <p class="guide-help">The yellow shading is behind the candlestick, so the decision candle remains fully visible. Dotted human evaluation lines use dark red (Super Bear), light red (Bear), grey (Neutral), light green (Bull), or dark green (Super Bull). Dotted blue represents the recorded five-class computer outcome. The permanent dotted line shows the actual close-to-close percentage change, independently of human or machine labels. Lines do not represent orders. TradingView Lightweight Charts™ · <a href="https://www.tradingview.com/" target="_blank" rel="noopener noreferrer">TradingView</a>.</p>
            <details><summary>Recent candle values</summary><div class="review-table-wrap"><table><thead><tr><th>Open time (<x-timezone-label />)</th><th>Open</th><th>High</th><th>Low</th><th>Close</th><th>Volume</th></tr></thead><tbody>
                @foreach(array_slice($snapshot['series'], -10) as $candle)<tr><th><x-display-time :value="$candle['time']" unit="seconds" precision="minutes" /></th>@foreach(['open', 'high', 'low', 'close', 'volume'] as $field)<td>{{ $candle[$field] }}</td>@endforeach</tr>@endforeach
            </tbody></table></div></details>
        </section>
        @if(collect($snapshot['features'] ?? [])->contains(fn ($value) => is_numeric($value) && is_finite((float) $value)))
        <section class="guide-panel"><h2>Indicators and context</h2>
            <p class="guide-help">Frozen features from the selected dataset. Missing context is not filled with current information.</p>
            <dl class="human-training-indicators">
                @foreach($snapshot['features'] as $key => $value)
                    <div><dt>{{ str_replace('_', ' ', $key) }}</dt><dd>{{ is_numeric($value) ? \App\Helpers\Decimal::format($value, 5) : 'Unavailable' }}</dd></div>
                @endforeach
            </dl>
        </section>
        @endif
        <section class="guide-panel"><h2>Your Outcome assessment</h2>
            @if($review->submitted_at)
                <p><strong>{{ ucwords(str_replace('_', ' ', $review->label)) }}</strong> · {{ $review->confidence === null ? 'Confidence not supplied' : $review->confidence.'% self-rated confidence' }}</p>
                @if($review->reason)<p>{{ $review->reason }}</p>@endif
                <p class="guide-help">Saved <x-display-time :value="$review->submitted_at" />. Submitted Outcome Training labels cannot be changed.</p>
                <h3>Model observation available at that time</h3>
                @if($snapshot['model_observation'])<p>{{ strtoupper($snapshot['model_observation']['action'] === 'hodl' ? 'hold' : $snapshot['model_observation']['action']) }} · {{ str_replace('_', ' ', $snapshot['model_observation']['reason']) }} · recorded <x-display-time :value="$snapshot['model_observation']['recorded_at_ms']" unit="milliseconds" /></p>
                @else<p>No model observation had been recorded by this decision time.</p>@endif
                <a class="guide-button" href="{{ route('human-training.index') }}">Choose another Outcome Training snapshot</a>
            @elseif(! $review->expires_at->isFuture())
                <p>This Outcome Training review has expired. <a href="{{ route('human-training.index') }}">Request another snapshot</a>.</p>
            @else
                <form method="POST" action="{{ route('human-training.update', $review->review_id) }}">@csrf @method('PUT')
                    <fieldset><legend>Expected market trend</legend><div class="human-labels">
                        @foreach($labels as $label)<label><input type="radio" name="label" value="{{ $label }}" @checked(old('label') === $label)>{{ ucwords(str_replace('_', ' ', $label)) }}</label>@endforeach
                    </div></fieldset>
                    <p class="guide-help">Bull: upward expectation. Bear: downward expectation. Super: stronger expectation. Neutral: no clear directional view.</p>
                    <div class="guide-grid"><label for="confidence">Confidence (optional, 0–100)<input id="confidence" name="confidence" type="number" min="0" max="100" step="1" value="{{ old('confidence') }}"></label>
                        <label for="reason">Reason (optional)<textarea id="reason" name="reason" rows="3" maxlength="2000">{{ old('reason') }}</textarea></label></div>
                    <div class="guide-inline">
                        <button type="submit" class="guide-button" name="next" value="same">Save Outcome assessment</button>
                        <button type="submit" class="guide-button" name="next" value="least_trained">Save Outcome assessment &amp; choose random scenario</button>
                        <button type="submit" class="review-control" name="label" value="skip">Skip this snapshot</button>
                    </div>
                    <p class="guide-help">Save continues with the same exchange/pair. Random scenario selects a different exchange/pair with the fewest saved Outcome assessments; ties are randomized.</p>
                    <p class="guide-help">Expires <x-display-time :value="$review->expires_at" />. Model output and other trainers’ answers are hidden while you decide.</p>
                </form>
            @endif
        </section>
    </section>
</x-layouts.app>
