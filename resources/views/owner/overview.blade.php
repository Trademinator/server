<x-owner.layout title="Server overview">
    <div class="owner-grid">
        <div class="owner-stat"><span>Registered users</span><strong>{{ \App\Helpers\Decimal::format($users->total) }}</strong><span>{{ $users->verified }} verified · {{ $users->suspended }} suspended</span></div>
        <div class="owner-stat"><span>Active subscriptions</span><strong>{{ \App\Helpers\Decimal::format($subscriptions) }}</strong><span>Across all accounts</span></div>
        <div class="owner-stat"><span>Overdue feeds</span><strong>{{ \App\Helpers\Decimal::format($overdue) }}</strong><span>More than 15 minutes past their next pull</span></div>
        <div class="owner-stat"><span>Requests today</span><strong>{{ \App\Helpers\Decimal::format($traffic->total) }}</strong><span>{{ \App\Helpers\Decimal::format($traffic->errors) }} server errors</span></div>
    </div>
    <section class="owner-panel">
        <h2>Latest activity</h2>
        <dl class="owner-details">
            <dt>Last successful collection</dt><dd>{{ $latestPull ?? 'No collection recorded yet' }}</dd>
            <dt>Last model build</dt><dd>{{ $latestModel ?? 'No model built yet' }}</dd>
            <dt>Action syslog</dt><dd>{{ config('operations.syslog_enabled') ? 'Enabled' : 'Disabled' }} · identifier <code>{{ config('operations.syslog_ident') }}</code></dd>
            <dt>GeoIP database</dt><dd>{{ $geoStatus['status'] }} @if($geoStatus['built_at']) · Built <x-display-time :value="$geoStatus['built_at']" /> @endif</dd>
            <dt>Access retention</dt><dd>{{ config('operations.retention_days') }} days · {{ config('operations.access_enabled') ? 'Recording enabled' : 'Recording disabled' }}</dd>
        </dl>
        <p class="owner-muted" style="margin-top:14px">Syslog delivery depends on the host logging service. Trace IDs connect requests, jobs and operation results.</p>
    </section>
    <div class="owner-grid">
        <section class="owner-panel"><h2>Collector status</h2><table class="owner-table"><thead><tr><th>Status</th><th>Feeds</th></tr></thead><tbody>
            @forelse($feeds as $feed)<tr><td>{{ $feed->status }}</td><td>{{ $feed->total }}</td></tr>@empty<tr><td colspan="2">No feeds yet.</td></tr>@endforelse
        </tbody></table></section>
        <section class="owner-panel"><h2>Current production models</h2>
            <x-knn-readiness :counts="$modelTotals" :total="$modelTotals['total']" />
            <p class="owner-muted">Readiness of the {{ \App\Helpers\Decimal::format($modelTotals['total']) }} current production model records and their CoinGecko context. Latest training attempts are shown separately in the <a href="{{ route('owner.intelligence') }}">intelligence report</a>.</p>
        </section>
    </div>
</x-owner.layout>
