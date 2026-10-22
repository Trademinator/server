<x-layouts.app title="OHLCV human labeling">
    <div class="py-6 mx-auto max-w-4xl px-4">
        <h1 class="text-xl font-bold">Human OHLCV labeling — {{ $market->exchange->class }} {{ $market->symbol }} ({{ $period }})</h1>
        <p>Closed candle {{ $timestamp }}. These labels require no calculated features. A later training run verifies the original candle before associating technical vectors.</p>
        <table class="w-full mt-3">
            <tr><th>Open</th><th>High</th><th>Low</th><th>Close</th><th>Volume</th></tr>
            <tr><td>{{ $candle['open'] }}</td><td>{{ $candle['high'] }}</td><td>{{ $candle['low'] }}</td><td>{{ $candle['close'] }}</td><td>{{ $candle['volume'] }}</td></tr>
        </table>
        <details class="my-4">
            <summary>Review the 25 preceding closed candles</summary>
            <table class="w-full">
                <thead><tr><th>Candle time (ms)</th><th>Open</th><th>High</th><th>Low</th><th>Close</th><th>Volume</th></tr></thead>
                <tbody>
                    @foreach ($history as $bar)
                        <tr><td>{{ $bar['microtimestamp'] }}</td><td>{{ $bar['open'] }}</td>
                            <td>{{ $bar['high'] }}</td><td>{{ $bar['low'] }}</td><td>{{ $bar['close'] }}</td>
                            <td>{{ $bar['volume'] }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </details>
        <p class="my-4">
            <a href="{{ route('human-training.ohlcv.show', ['market' => $market->market_id, 'candle' => $previous]) }}">Previous candle</a>
            <a class="ml-4" href="{{ route('human-training.ohlcv.show', ['market' => $market->market_id, 'candle' => $next]) }}">Next candle</a>
        </p>
        @if ($errors->any()) <p role="alert">{{ $errors->first() }}</p> @endif
        @if (session('status')) <p role="status">{{ session('status') }}</p> @endif
        <form method="post" action="{{ route('human-training.ohlcv.store', $market->market_id) }}" class="space-y-3">
            @csrf
            <input type="hidden" name="timestamp" value="{{ $timestamp }}">
            <label for="source">Training path</label>
            <select id="source" name="source" required>
                <option value="human_action">Human Action</option>
                <option value="human_outcome">Human Outcome</option>
            </select>
            <label for="label">Your judgment</label>
            <select id="label" name="label" required>
                <option value="buy">BUY (Action)</option><option value="hold">HOLD (Action)</option>
                <option value="sell">SELL (Action)</option>
                <option value="super_bear">Super bear (Outcome)</option><option value="bear">Bear (Outcome)</option>
                <option value="neutral">Neutral (Outcome)</option><option value="bull">Bull (Outcome)</option>
                <option value="super_bull">Super bull (Outcome)</option>
            </select>
            <button type="submit">Save judgment</button>
        </form>
    </div>
</x-layouts.app>
