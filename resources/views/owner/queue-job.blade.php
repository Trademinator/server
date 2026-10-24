<x-owner.layout title="Job details">
    <section class="owner-panel">
        <p><a href="{{ route('owner.queue-jobs.index', [...$selection, 'state' => $detail['state']]) }}">← {{ $selection['connection'] }} / {{ $selection['queue'] }}</a></p>
        <h2>{{ class_basename($detail['name']) }}</h2>
        <dl class="grid grid-cols-1 md:grid-cols-2 gap-3 my-4">
            <dt>Job ID</dt><dd><code>{{ $detail['uuid'] }}</code></dd>
            <dt>Status</dt><dd>{{ ucfirst($detail['state']) }}</dd>
            <dt>Exchange / market</dt><dd><strong class="text-green-700 dark:text-green-400">{{ $detail['exchange'] ?? '—' }} {{ $detail['symbol'] ?? '' }}</strong></dd>
            <dt>Candle period</dt><dd>{{ $detail['period'] ?? '—' }}</dd>
            <dt>Active subscribers</dt><dd>{{ $detail['subscriber_count'] ?? '—' }}</dd>
            <dt>Priority reason</dt><dd>{{ $detail['priority_reason'] ?? 'Queue order' }}</dd>
            <dt>Order</dt><dd>{{ ($detail['manual'] ?? false) ? 'Manual' : 'Automatic' }}</dd>
            <dt>Originally requested</dt><dd>@if($detail['requested_at'] ?? $detail['created_at'] ?? null)<x-display-time :value="$detail['requested_at'] ?? $detail['created_at']" unit="seconds" />@else—@endif</dd>
            <dt>Queued at</dt><dd>@if($detail['created_at'] ?? null)<x-display-time :value="$detail['created_at']" unit="seconds" />@else—@endif</dd>
            <dt>{{ $detail['state'] === 'reserved' ? 'Reservation expires' : 'Available' }}</dt><dd>@if($detail['available_at'] ?? null)<x-display-time :value="$detail['available_at']" unit="seconds" />@else—@endif</dd>
            <dt>Attempts</dt><dd>{{ $detail['attempts'] ?? 0 }}</dd>
            <dt>Maximum attempts</dt><dd>{{ $detail['max_tries'] ?? 'Worker default' }}</dd>
            <dt>Job timeout</dt><dd>{{ isset($detail['timeout']) ? $detail['timeout'].' seconds' : 'Worker default' }}</dd>
            <dt>Remaining chained jobs</dt><dd>{{ $detail['chain_remaining'] ?? 0 }}</dd>
            @foreach(($detail['options'] ?? []) as $name => $value)
                <dt>{{ ucfirst(str_replace('_', ' ', $name)) }}</dt><dd>{{ is_bool($value) ? ($value ? 'Yes' : 'No') : $value }}</dd>
            @endforeach
        </dl>
        @if($detail['state'] === 'reserved')
            <p>This job has already been claimed. It cannot be moved or cancelled. <a href="{{ route('owner.queue-backlog') }}#workers">View worker activity</a>.</p>
        @elseif($detail['can_cancel'] ?? false)
            @if(($detail['chain_remaining'] ?? 0) > 0)
                <p>Cancelling this job also prevents its {{ $detail['chain_remaining'] }} remaining chained jobs from being dispatched.</p>
            @endif
            <form method="POST" action="{{ route('owner.queue-jobs.destroy', $detail['uuid']) }}">
                @csrf @method('DELETE')
                <input type="hidden" name="connection" value="{{ $selection['connection'] }}">
                <input type="hidden" name="queue" value="{{ $selection['queue'] }}">
                <button type="submit" class="owner-danger">Cancel pending job</button>
            </form>
            <p class="owner-muted">Cancellation is accepted only while this job is pending. It does not stop a running job or remove the market subscription.</p>
        @else
            <p>{{ $detail['cancel_reason'] ?? 'This job cannot be cancelled safely from this page.' }}</p>
        @endif
    </section>
</x-owner.layout>
