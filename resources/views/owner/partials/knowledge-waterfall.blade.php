@php
    $names = ['ohlcv' => 'OHLCV', 'features' => 'Features', 'labels' => 'Labels',
        'dataset' => 'Snapshot', 'ai' => 'AI'];
    $stateLabels = ['green' => 'Healthy', 'yellow' => 'Warning', 'red' => 'Needs update', 'gray' => 'Blocked upstream'];
    $redCount = count(array_filter($waterfall, fn ($stage) => $stage['state'] === 'red'));
    $yellowCount = count(array_filter($waterfall, fn ($stage) => $stage['state'] === 'yellow'));
    $summary = $redCount > 0 ? $redCount.' need update'
        : ($yellowCount > 0 ? $yellowCount.' warning'.($yellowCount === 1 ? '' : 's') : 'All healthy');
@endphp
<details class="knowledge-waterfall-panel">
    <summary class="knowledge-waterfall-toggle" aria-label="Expand knowledge waterfall: {{ $summary }}">
        <span class="knowledge-waterfall-heading">Knowledge waterfall</span>
        <span class="knowledge-waterfall-mini" aria-hidden="true">
            @foreach ($names as $step => $name)
                <span class="knowledge-waterfall-mini-bar knowledge-waterfall-bar-{{ $waterfall[$step]['state'] }}"></span>
            @endforeach
        </span>
        <span class="knowledge-waterfall-summary">{{ $summary }}</span>
    </summary>
    <div class="knowledge-waterfall-content">
        <div class="knowledge-waterfall-stages" role="group" aria-label="Knowledge stages and readiness">
            @foreach ($names as $step => $name)
                @php($health = $waterfall[$step])
                <section class="knowledge-waterfall-stage" aria-label="{{ $name }}: {{ $stateLabels[$health['state']] }}">
                    <div class="knowledge-waterfall-stage-header">
                        <strong>{{ $name }}</strong>
                        <span>{{ $stateLabels[$health['state']] }}</span>
                    </div>
                    <span class="knowledge-waterfall-stage-bar knowledge-waterfall-bar-{{ $health['state'] }}" aria-hidden="true"></span>
                    <p>{{ $health['detail'] }}</p>
                    @if ($step === 'labels' && !empty($health['sources']))
                        <div class="knowledge-label-sources" aria-label="Four labeling sources">
                            @foreach ($health['sources'] as $source => $sourceHealth)
                                <p><span class="knowledge-waterfall-mini-bar knowledge-waterfall-bar-{{ $sourceHealth['state'] }}" aria-hidden="true"></span>
                                    <strong>{{ ucwords(str_replace('_', ' ', $source)) }}:</strong> {{ $sourceHealth['detail'] }}</p>
                            @endforeach
                        </div>
                    @endif
                    @if ($health['through_ms'] !== null)
                        <small>Watermark: <x-display-time :value="$health['through_ms']" unit="milliseconds" /></small>
                    @endif
                </section>
            @endforeach
        </div>
        @if ($marketId !== null)
            <form method="POST" action="{{ route('owner.intelligence.action', $marketId) }}" class="knowledge-waterfall-actions">
                @csrf
                <button type="submit" name="action" value="collect">Sync OHLCV</button>
                <button type="submit" name="action" value="features">Build features</button>
                <button type="submit" name="action" value="labels">Analyze labels</button>
                <button type="submit" name="action" value="snapshot">Build snapshot</button>
                <button type="submit" name="action" value="train">Train AI</button>
            </form>
            <a class="knowledge-waterfall-repair" href="{{ route('owner.history-recovery.show', $marketId) }}">Repair gaps / full recovery →</a>
            <p class="owner-muted knowledge-waterfall-note">Actions are queued independently. Full recovery uses the existing confirmed workflow.</p>
        @else
            <p class="owner-muted knowledge-waterfall-note">On-demand actions require an active subscription and selected candle period.</p>
        @endif
    </div>
</details>
