<x-owner.layout title="Queue backlog">
    <section class="owner-panel" id="queue-backlog"><h2>Queue backlog</h2>
        <p class="owner-muted">Configured queue connection: {{ $queueDriver }}. Monitored connections: {{ implode(', ', $queueBacklog['connections']) ?: 'None' }}.</p>
        @foreach($queueBacklog['notices'] as $notice)<p class="owner-muted">{{ $notice }}</p>@endforeach
        @foreach($queueBacklog['errors'] as $error)<p role="status">{{ $error }} Backlog counts may be incomplete.</p>@endforeach
        <div class="owner-scroll"><table class="owner-table"><thead><tr><th>Connection / backend</th><th>Queue</th><th>Waiting</th><th>Delayed</th><th>Reserved</th><th>Total</th><th>Oldest waiting job</th></tr></thead><tbody>
            @forelse($queueBacklog['rows'] as $queue)
                <tr>
                    <td>{{ $queue['connection'] }} / {{ $queue['driver'] }}</td>
                    <td>{{ $queue['driver'] === 'sqs' ? basename($queue['queue']) : $queue['queue'] }}</td>
                    <td>{{ $queue['waiting'] }}</td><td>{{ $queue['delayed'] }}</td><td>{{ $queue['reserved'] }}</td><td>{{ $queue['total'] }}</td>
                    <td>@if($queue['oldest'] !== null)<x-display-time :value="$queue['oldest']" unit="seconds" />@else—@endif</td>
                </tr>
            @empty
                <tr><td colspan="7">{{ $queueBacklog['errors'] === [] ? 'No queued jobs in the monitored connections.' : 'Queue backlog could not be fully read.' }}</td></tr>
            @endforelse
        </tbody></table></div>
        <p class="owner-muted">Reserved jobs have been claimed by a worker. Oldest-job times are shown when available.@if(collect($queueBacklog['rows'])->contains('driver', 'sqs')) SQS counts are approximate.@endif</p>
        <h3>Recent recorded job failures</h3><div class="owner-scroll"><table class="owner-table"><thead><tr>
            @foreach (['uuid' => 'Job UUID', 'connection' => 'Connection / queue', 'exception' => 'Exception', 'failed_at' => 'Failed at'] as $column => $label)
                @php
                    $nextDirection = $failedSort === $column ? ($failedDirection === 'asc' ? 'desc' : 'asc') : ($column === 'failed_at' ? 'desc' : 'asc');
                    $url = route('owner.queue-backlog', array_replace($failedFilters, ['failed_sort' => $column, 'failed_direction' => $nextDirection])).'#queue-backlog';
                @endphp
                <th scope="col" @if ($failedSort === $column) aria-sort="{{ $failedDirection === 'asc' ? 'ascending' : 'descending' }}" @endif>
                    <a href="{{ $url }}"
                       aria-label="Sort by {{ $label }}, {{ $nextDirection === 'asc' ? 'ascending' : 'descending' }}"
                       title="Sort by {{ $label }}">
                        {{ $label }} <span aria-hidden="true">{{ $failedSort === $column ? ($failedDirection === 'asc' ? '↑' : '↓') : '↕' }}</span>
                    </a>
                </th>
            @endforeach
            <th scope="col">Exchange</th><th scope="col">Market</th><th scope="col">Period</th>
            <th scope="col">Actions</th>
        </tr></thead><tbody>
            @forelse($failed as $job)
                <tr>
                    <td><code>{{ $job->uuid }}</code></td>
                    <td>{{ $job->connection }} / {{ $job->queue }}</td>
                    <td><code>{{ $job->exception_name }}</code></td>
                    <td><x-display-time :value="$job->failed_at" /></td>
                    <td>{{ $job->market_exchange ?? '—' }}</td>
                    <td>{{ $job->market_symbol ?? '—' }}</td>
                    <td>{{ $job->market_period ?? '—' }}</td>
                    <td><x-owner.failed-job-actions :job="$job->uuid" :view="true" /></td>
                </tr>
            @empty
                <tr><td colspan="8">No recorded failures.</td></tr>
            @endforelse
        </tbody></table></div>
    </section>
</x-owner.layout>
