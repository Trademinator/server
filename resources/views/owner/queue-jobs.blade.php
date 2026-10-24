<x-owner.layout title="Queued work">
    <section class="owner-panel">
        <p><a href="{{ route('owner.queue-backlog') }}">← Queue backlog and workers</a></p>
        <h2>{{ $selection['connection'] }} / {{ $selection['queue'] }}</h2>
        <nav aria-label="Queue states" class="flex flex-wrap gap-4 my-4">
            @foreach(['waiting' => 'Ready', 'delayed' => 'Delayed', 'reserved' => 'Reserved / running'] as $value => $label)
                <a href="{{ route('owner.queue-jobs.index', [...$selection, 'state' => $value]) }}" @if($state === $value) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
        </nav>
        @if($state === 'waiting')
            <p class="owner-muted">Shown in execution order. Subscriber demand and waiting time determine automatic priority. Manual moves apply to ready jobs; a worker may claim a job while you are viewing this page.</p>
        @elseif($state === 'delayed')
            <p class="owner-muted">These jobs become eligible at their available time. They can be cancelled while pending; moving a job cannot bypass its delay.</p>
        @else
            <p class="owner-muted">These jobs have been claimed by a worker. Their details are available for inspection; they cannot be moved or cancelled.</p>
        @endif
        @if($error)<p role="alert">{{ $error }}</p>@endif
        <div class="owner-scroll"><table class="owner-table">
            <thead><tr><th>Order / job</th><th>Market / period</th><th>Subscribers</th><th>Priority</th><th>Waiting since</th><th>{{ $state === 'reserved' ? 'Reservation expires' : 'Available' }}</th><th>Attempts</th><th>Actions</th></tr></thead>
            <tbody>
            @forelse($snapshot['jobs'] as $job)
                <tr>
                    <td>{{ ($snapshot['page'] - 1) * $snapshot['per_page'] + $loop->iteration }}. {{ class_basename($job['name']) }}<br><code>{{ $job['uuid'] }}</code></td>
                    <td><strong class="text-green-700 dark:text-green-400">{{ $job['exchange'] ?? '—' }} {{ $job['symbol'] ?? '' }}</strong><br>{{ $job['period'] ?? '—' }}</td>
                    <td>{{ $job['subscriber_count'] ?? '—' }}</td>
                    <td>{{ $job['priority_reason'] ?? 'Queue order' }}@if($job['manual'] ?? false)<br><strong>Manual order</strong>@endif</td>
                    <td>@if($job['requested_at'] ?? $job['created_at'] ?? null)<x-display-time :value="$job['requested_at'] ?? $job['created_at']" unit="seconds" />@else—@endif</td>
                    <td>@if($job['available_at'] ?? null)<x-display-time :value="$job['available_at']" unit="seconds" />@else—@endif</td>
                    <td>{{ $job['attempts'] ?? 0 }}</td>
                    <td>
                        <a href="{{ route('owner.queue-jobs.show', ['job' => $job['uuid'], ...$selection]) }}">Details</a>
                        @if($state === 'waiting' && ($job['can_move'] ?? true))
                            <form method="POST" action="{{ route('owner.queue-jobs.move', $job['uuid']) }}" class="flex flex-wrap gap-2 mt-2">
                                @csrf
                                <input type="hidden" name="connection" value="{{ $selection['connection'] }}">
                                <input type="hidden" name="queue" value="{{ $selection['queue'] }}">
                                @foreach(['top' => 'Top', 'up' => 'Up', 'down' => 'Down', 'bottom' => 'Bottom'] as $direction => $label)
                                    <button type="submit" name="direction" value="{{ $direction }}" aria-label="Move {{ $job['uuid'] }} {{ $direction }}">{{ $label }}</button>
                                @endforeach
                                @if($job['manual'] ?? false)<button type="submit" name="direction" value="automatic">Automatic</button>@endif
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="8">{{ $error ? 'Job details are unavailable.' : 'No jobs in this queue state.' }}</td></tr>
            @endforelse
            </tbody>
        </table></div>
        @if(!$error)
            <nav aria-label="Queued jobs pages" class="flex flex-wrap gap-4 my-4">
                <span>{{ $snapshot['total'] }} jobs · Page {{ $snapshot['page'] }}</span>
                @if($snapshot['page'] > 1)<a href="{{ route('owner.queue-jobs.index', [...$selection, 'state' => $state, 'page' => $snapshot['page'] - 1]) }}">Previous</a>@endif
                @if($snapshot['page'] * $snapshot['per_page'] < $snapshot['total'])<a href="{{ route('owner.queue-jobs.index', [...$selection, 'state' => $state, 'page' => $snapshot['page'] + 1]) }}">Next</a>@endif
            </nav>
        @endif
    </section>
</x-owner.layout>
