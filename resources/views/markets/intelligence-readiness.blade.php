<section class="guide-panel">
    <h2>What is missing?</h2>
    <x-knn-readiness :report="$report" :coingecko="$coingecko" />
    <p class="guide-help">Green: Outcome KNN. Blue: Action KNN. Yellow: fresh, complete CoinGecko context for this market. A KNN check means the model is current, validated and enabled for scoring; it can still abstain on an individual candle. Human Training is optional. Human Outcome Training contributes to Outcome KNN and Human Action Training contributes to Action KNN using the dynamic human-training weight.</p>
    @can('manage-server')
        <p><a class="review-control" href="{{ route('owner.history-recovery.show', $item->market) }}">Repair history and rebuild</a></p>
    @endcan
    <p class="guide-notice">{{ $progress['action'] }}</p>
    @if (!$progress['evidence_evaluated'])
        <p>The current candle has not reached KNN voting. Confidence and effective neighbors are fallback zeros, not measured trading evidence.</p>
    @endif
    @if ($progress['issues'] !== [])
        <ul class="intelligence-issues">
            @foreach ($progress['issues'] as $issue)
                <li>{{ $issue }}</li>
            @endforeach
        </ul>
    @endif
    @if ($progress['history'])
        <x-intelligence-progress :label="$progress['history']['sampled'] ? 'Potential training rows (checked sample)' : 'Potential training rows now (estimate)'" :value="$progress['history']['potential']" :target="$progress['minimum']" native />
        <p class="guide-help">{{ \App\Helpers\Decimal::format($progress['history']['closed']) }} current-version closed feature rows in the recent build window. Of the {{ \App\Helpers\Decimal::format($progress['history']['checked']) }} rows checked, {{ \App\Helpers\Decimal::format($progress['history']['complete']) }} contain every selected feature. {{ \App\Helpers\Decimal::format($progress['history']['immature']) }} complete rows are reserved for inference or still waiting for their {{ $progress['horizon'] }}-candle outcome.</p>
        @if ($progress['history']['sampled'])
            <p class="guide-help">Only the latest {{ \App\Helpers\Decimal::format($progress['history']['checked']) }} rows were checked for this estimate. Older eligible rows are also available to training, which uses all available eligible history.</p>
        @endif
        <p class="guide-help">Potential rows are counted before source-candle checks, semantic warm-up, pattern and lead/lag exclusions. The actual model counts below come from the last training run.</p>
        @if ($progress['history']['latest_ms'])
            <p>Latest closed feature: <x-display-time :value="$progress['history']['latest_ms']" unit="milliseconds" /></p>
        @endif
    @endif
    @if ($progress['full_schema'])
        @php($fullSchema = $progress['full_schema'])
        <h3>Optional CoinGecko context</h3>
        <x-intelligence-progress label="CoinGecko context (optional)" :value="$fullSchema['context_available']" :target="$fullSchema['context_total']" native />
        @if ($fullSchema['full_ready'])
            <p class="guide-notice"><strong>Technical/Full KNN ready.</strong> CoinGecko is optional and its independent advisor has separate readiness.</p>
        @else
            @if ($fullSchema['missing'] !== [])
                <p><strong>Optional CoinGecko context incomplete.</strong> CoinGecko context is incomplete for {{ $item->market->exchange->name }} · {{ $item->market->symbol }}.</p>
            @elseif ($fullSchema['technical_missing'] !== [])
                <p><strong>Technical/Full KNN unavailable.</strong> CoinGecko context is complete, but the latest candle is still missing technical history required by the full schema.</p>
            @elseif ($fullSchema['invalid'])
                <p><strong>Latest features unavailable.</strong> The latest feature vector contains an invalid numeric value and must be rebuilt.</p>
            @else
                <p><strong>Latest features unavailable.</strong> Rebuild the latest M2 features and inspect the context status below.</p>
            @endif
            <p>CoinGecko mapping: <strong>{{ match ($fullSchema['mapping']['status']) {
                'resolved' => 'Resolved',
                'pending' => 'Pending',
                'ambiguous' => 'Ambiguous',
                'unmapped' => 'Unmapped',
                'unsupported' => 'Unsupported',
                default => 'Not created',
            } }}</strong>
                @if ($fullSchema['mapping']['coin_id'])
                    · {{ $fullSchema['mapping']['coin_name'] ?: $fullSchema['mapping']['coin_id'] }} (<code>{{ $fullSchema['mapping']['coin_id'] }}</code>)
                    @if ($fullSchema['mapping']['vs_currency']) / {{ strtoupper($fullSchema['mapping']['vs_currency']) }} @endif
                @endif
            </p>
            @if ($fullSchema['mapping']['error'])
                <p class="guide-help">{{ $fullSchema['mapping']['error'] }}</p>
            @endif
            @if ($fullSchema['missing'] !== [])
                <details>
                    <summary>Missing CoinGecko features ({{ count($fullSchema['missing']) }})</summary>
                    <ul class="intelligence-issues">
                        @foreach ($fullSchema['missing'] as $feature)
                            <li>{{ ucwords(str_replace(['context.', '_'], ['', ' '], $feature)) }}</li>
                        @endforeach
                    </ul>
                </details>
            @endif
            @if ($fullSchema['technical_ready'])
                <p class="guide-help"><strong>Technical schema remains available.</strong> Missing CoinGecko context does not require disabling technical-only intelligence for this market.</p>
            @elseif ($fullSchema['technical_missing'] !== [])
                <p class="guide-help">Technical schema is also waiting for: {{ implode(', ', array_map(fn ($feature) => ucwords(str_replace(['return.', '_'], ['', ' '], $feature)), $fullSchema['technical_missing'])) }}.</p>
            @endif
            @if ($fullSchema['invalid'])
                <p class="guide-help">At least one latest feature value is outside its expected numeric range. Rebuild M2 features before using either schema.</p>
            @endif
        @endif
        <p class="guide-help">CoinGecko does not need to list this exchange. Trademinator maps the base asset and exact quote currency; the exchange remains the authoritative source for its own candles.</p>
    @endif
    @if ($progress['next_training'])
        <p>Next weekly training dispatch: <x-display-time :value="$progress['next_training']" /> ({{ $progress['next_training']->diffForHumans() }}). This is a schedule, not confirmation that a worker is running.</p>
    @endif
    <p class="guide-help">M4 jobs require a worker draining the <code>{{ $progress['queue'] }}</code> queue. Training is separate from M2 feature collection. Refresh this page to update the live counts; model counts change only after a rebuild.</p>
    @if ($period)
        <details>
            <summary>How to build or troubleshoot this model</summary>
            <p>After collection and M2 features are available, run a direct build for this market:</p>
            @if (in_array($progress['schema'], ['core', 'technical', 'enhanced', 'full'], true))
                <pre class="intelligence-command"><code>php artisan trademinator:knn-build {{ escapeshellarg($item->market->exchange->class) }} {{ escapeshellarg($item->market->symbol) }} {{ escapeshellarg($period) }} --schema={{ escapeshellarg($progress['schema']) }}</code></pre>
                <p>This preserves the currently selected <code>{{ $progress['schema'] }}</code> schema.</p>
            @else
                <p>The current model uses a custom schema. Re-run the original command with the same <code>--features</code> list rather than silently substituting another schema.</p>
            @endif
            <p>For automatic builds, run <code>php artisan trademinator:dispatch-market-intelligence</code> and the cron worker documented in <code>docs/CRONTABS.md</code>. Review <code>php artisan queue:failed</code> if a build never appears. A completed weekly generation, including an abstaining model, is not rebuilt by redispatching in the same week; use the direct command after adding history.</p>
        </details>
    @endif
</section>
