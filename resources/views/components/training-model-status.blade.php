@props(['trainingReport' => null, 'productionReport' => null])

@php
    $productionReady = \App\Domain\Intelligence\ModelStore::isReadyReport($productionReport);
    $productionStates = \App\Domain\Intelligence\ModelStore::knnReadiness($productionReport);
    $productionReason = ! $productionStates['outcome']['ready'] ? $productionStates['outcome']['reason']
        : (! $productionStates['action']['ready'] ? $productionStates['action']['reason'] : 'model_unavailable');
@endphp
<div {{ $attributes->merge(['class' => 'guide-help']) }}>
    @if ($trainingReport)
        <p><strong>Latest completed training:</strong> <code>{{ $trainingReport['model_id'] ?? 'Not recorded' }}</code>
            @if (! empty($trainingReport['created_at']))
                · completed <x-display-time :value="$trainingReport['created_at']" />
            @endif
        </p>
        <p><strong>Recorded validation:</strong>
            @foreach (['outcome' => 'Outcome', 'action' => 'Action'] as $component => $label)
                {{ $label }}: {{ ucfirst($trainingReport[$component]['status'] ?? 'unavailable') }}
                (<code>{{ $trainingReport[$component]['reason'] ?? 'not_recorded' }}</code>){{ $loop->last ? '.' : ' ·' }}
            @endforeach
        </p>
    @else
        <p><strong>Latest completed training:</strong> No model build recorded yet.</p>
    @endif
    <p><strong>Production: {{ $productionReady ? 'Available' : 'Unavailable' }}.</strong>
        @if ($productionReport)
            Published model <code>{{ $productionReport['model_id'] ?? 'Not recorded' }}</code>.
            @if (! $productionReady)
                {{ ucfirst(str_replace('_', ' ', $productionReason)) }} (<code>{{ $productionReason }}</code>).
            @endif
        @else
            No model has been published.
        @endif
    </p>
    @if ($trainingReport && ($trainingReport['model_id'] ?? null) !== ($productionReport['model_id'] ?? null))
        <p>The latest training build has not replaced the published model. Current signals continue to use the published model when it is available.</p>
    @endif
</div>
