<x-layouts.app title="Human training">
    @include('markets.guide-styles')
    @include('markets.review-styles')
    <section class="pair-guide pair-review">
        <header class="guide-hero"><p class="review-eyebrow">M4.4 · Human-guided learning</p><h1>Human training</h1>
            <p>Use Outcome Training to assess a whole frozen setup, or Action Training to mark the BUY, HOLD or SELL action you would take on individual candles.</p></header>
        @if(session('status'))<p class="guide-notice" role="status">{{ session('status') }}</p>@endif
        @if($errors->any())<div class="guide-notice guide-error" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <p><a class="guide-button" href="{{ route('human-training.evaluations.index') }}">Explore historical model evaluations</a></p>
        <section class="guide-panel">
            <h2>Label from OHLCV — no Features needed</h2>
            <p>Record Human Action or Human Outcome opinions directly from closed candles. Technical feature vectors are attached only later during model learning.</p>
            <div class="guide-grid">
                @foreach ($availableMarkets as $market)
                    @if ($market['period'] !== '')
                        <a class="guide-button" href="{{ route('human-training.ohlcv.show', $market['market_id']) }}">
                            {{ $market['exchange'] }} {{ $market['pair'] }} ({{ $market['period'] }})
                        </a>
                    @endif
                @endforeach
            </div>
        </section>
        <div class="guide-grid">
            <section class="guide-panel">
                <p class="review-eyebrow">Existing workflow</p>
                <h2>Outcome Training</h2>
                <p>Assess what you expect over the dataset horizon from a frozen historical snapshot. You have submitted {{ \App\Helpers\Decimal::format($completed) }} Outcome labels.</p>
                @if($pending)
                    <a class="guide-button" href="{{ route('human-training.show', $pending->review_id) }}">Continue Outcome Training</a>
                @else
                    @if($datasets === [])
                        <p>No current semantic training dataset is ready yet. Subscribed pairs remain listed below in green with the reason they cannot be selected.</p>
                    @endif
                    <form method="POST" action="{{ route('human-training.store') }}">@csrf
                        <label for="trend-dataset">Market and frozen dataset</label>
                        <x-subscribed-pair-select name="dataset" id="trend-dataset" :value="$selectedDataset ?? ''"
                            :datasets="$datasets" :all-subscribed="true" :sort="['exchange', 'pair', 'period']"
                            :show-unavailable-datasets="true" required />
                        <p class="guide-help">Subscribed pairs without a usable semantic dataset are shown in green and disabled. A random unseen snapshot is selected from an available dataset; future prices, objective outcomes, model output and other trainers’ answers stay hidden while you assess the trend.</p>
                        <button class="guide-button" type="submit" @disabled($datasets === [])>Start Outcome Training</button>
                    </form>
                @endif
            </section>
            <section class="guide-panel">
                <p class="review-eyebrow">Per-candle actions</p>
                <h2>Action Training</h2>
                <p>Click individual candles and mark the action you would have taken there. You currently have {{ \App\Helpers\Decimal::format($candleCompleted) }} saved candle labels.</p>
                @if($datasets === [])
                    <p>No current semantic training dataset is ready yet. Subscribed pairs remain listed below in green with the reason they cannot be selected.</p>
                @endif
                <form method="POST" action="{{ route('human-training.candles.start') }}">@csrf
                    <label for="candle-dataset">Market and frozen dataset</label>
                    <x-subscribed-pair-select name="dataset" id="candle-dataset" :value="$selectedDataset ?? ''"
                        :datasets="$datasets" :all-subscribed="true" :sort="['exchange', 'pair', 'period']"
                        :show-dataset-samples="false" :show-unavailable-datasets="true" required />
                    <p class="guide-help">Subscribed pairs without a usable semantic dataset are shown in green and disabled. The replay opens on an unlabelled candle when possible; selecting an earlier candle truncates the chart there so later candles are not shown while you decide.</p>
                    <button class="guide-button" type="submit" @disabled($datasets === [])>Start Action Training</button>
                </form>
            </section>
        </div>
        <section class="guide-panel"><h2>How the two kinds of labels are used</h2>
            <p><strong>Outcome Training</strong> uses Super Bull, Bull, Neutral, Bear and Super Bear to describe your expectation over the displayed horizon. Those labels train the human source of Outcome KNN.</p>
            <p><strong>Action Training</strong> stores BUY, HOLD or SELL on the exact selected candle together with that candle’s immutable feature vector. Unlabelled candles mean no human opinion; they are never silently converted to HOLD. Saved candle actions can be changed or removed.</p>
            <p>The next model build trains algorithmic and human sources independently for each KNN. Human influence grows with the amount of Human Training and is capped at 60%; algorithmic evidence always retains at least 40% when both sources are available.</p>
            <p class="guide-help">Human labels remain retrospective research inputs, not exchange orders, fills, or probabilities of profit. Outcome KNN and Action KNN are reconciled by the Server decision matrix before a SELL, HOLD or BUY suggestion is emitted.</p>
        </section>
        @if($statistics !== null)
            <section class="guide-panel"><h2>Trainer agreement</h2>
                <p>Outcome Training: latest {{ \App\Helpers\Decimal::format($statistics['snapshots']) }} reviewed snapshots by candle time (maximum 1,000): {{ $statistics['shared'] }} reviewed by multiple trainers; {{ $statistics['disputed'] }} with differing labels.</p>
                <p>Action Training: {{ \App\Helpers\Decimal::format($statistics['candle_labels']) }} current BUY/HOLD/SELL labels across authorized trainers.</p>
                <div class="review-table-wrap"><table><thead><tr><th>Trainer UUID</th><th>Outcome labels</th><th>Outcome agreement with peers</th></tr></thead><tbody>
                    @forelse($statistics['trainers'] as $id => $trainer)<tr><td><code>{{ $id }}</code></td><td>{{ $trainer['labels'] }}</td><td>{{ $trainer['peer_comparisons'] ? \App\Helpers\Decimal::format(100 * $trainer['peer_agreements'] / $trainer['peer_comparisons'], 1).'% ('.$trainer['peer_comparisons'].' comparisons)' : 'Awaiting independent reviews' }}</td></tr>
                    @empty<tr><td colspan="3">No submitted Outcome labels yet.</td></tr>@endforelse
                </tbody></table></div>
                <p class="guide-help">Trainer confidence does not increase voting weight. Ties and insufficient agreement are excluded from training. Action consensus uses the same trainer authorization and agreement gates.</p>
                <form method="POST" action="{{ route('human-training.export') }}">@csrf<button class="review-control" type="submit">Export Outcome and Action Training</button></form>
                <p><a href="{{ route('owner.intelligence') }}">View model validation and both human-training comparisons</a></p>
            </section>
        @endif
    </section>
</x-layouts.app>
