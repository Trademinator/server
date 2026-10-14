<?php

use App\Domain\Intelligence\SignalJournal;
use App\Domain\Intelligence\SignalMemoryAtr;
use App\Domain\Intelligence\SignalMemoryFilter;
use App\Domain\Intelligence\SignalSemantics;
use App\Models\Market;
use App\Models\MarketFeed;
use App\Models\MarketSignal;
use App\Models\MarketSubscription;
use App\Models\User;
use App\Repositories\TickerRepository;
use Illuminate\Support\Facades\Artisan;
use Trademinator\Indicators\Traits\Technical;

function signalMemoryMarket(): Market
{
    $fixture = MarketSignal::factory()->create();
    $market = $fixture->market;
    $fixture->delete();
    $market->load('exchange');
    MarketFeed::query()->create(['market_id' => $market->getKey(), 'selected_period' => '1m', 'status' => 'active']);
    MarketSubscription::query()->create(['user_id' => User::factory()->create()->getKey(),
        'market_id' => $market->getKey(), 'active' => true]);

    return $market;
}

function signalMemoryBars(Market $market, array $closes, array $omit = []): array
{
    $start = strtotime('2024-01-01 00:00:00 UTC') * 1000;
    $bars = [];
    foreach ($closes as $index => $close) {
        $bars[$index] = ['microtimestamp' => $start + $index * 60000,
            'open' => $close, 'close' => $close, 'high' => bcadd($close, '0.020', 24),
            'low' => bcsub($close, '0.020', 24), 'volume' => '1'];
    }
    $saved = array_diff_key($bars, array_flip($omit));
    app(TickerRepository::class)->saveTickers($market->exchange->class, $market->symbol, '1m', array_values($saved));

    return $bars;
}

function signalMemoryProposal(Market $market, array $bar, string $action = 'buy', string $reason = 'supported'): array
{
    return ['exchange' => $market->exchange->class, 'symbol' => $market->symbol, 'period' => '1m',
        'model_id' => null, 'decision_at_ms' => $bar['microtimestamp'] + 60000,
        'action' => $action, 'reason' => $reason, 'confidence' => 0.85,
        'reference_price' => $bar['close'], 'reference_price_source' => 'closed_candle_close',
        'scoring' => ['version' => 'outcome-action-matrix-v3', 'resolved_action' => $action,
            'action' => ['action' => $action, 'reason' => 'supported', 'confidence' => 0.85]]];
}

beforeEach(function () {
    $this->travelTo('2024-01-01 01:00:00 UTC');
    config(['signal_memory.enabled' => true, 'archive.enabled' => false]);
});

it('preserves raw KNN evidence, persists memory through JSON and rearms after accumulation', function () {
    $market = signalMemoryMarket();
    $bars = signalMemoryBars($market, ['2.005', '2.005', '2.005', '2.005', '2.000', '1.985', '1.970', '1.955', '1.950']);
    $filter = app(SignalMemoryFilter::class);
    $previous = null;
    foreach ([4, 5, 6, 7, 8] as $index) {
        $raw = signalMemoryProposal($market, $bars[$index]);
        $signal = $filter->apply($market, '1m', $raw, $previous);
        expect($signal['scoring']['action'])->toBe($raw['scoring']['action'])
            ->and($signal['scoring']['signal_memory']['raw_resolved_action'])->toBe('buy')
            ->and($signal['scoring']['resolved_action'])->toBe($signal['action'] === 'hodl' ? 'hold' : $signal['action']);
        if ($index === 7) {
            expect($signal['action'])->toBe('buy')->and($signal['reason'])->toBe('supported')
                ->and($signal['scoring']['signal_memory']['path'])->toBe('accumulated_displacement')
                ->and($signal['signal_memory_state']['anchor']['t0_price'])->toBe('1.955');
        } else {
            expect($signal['action'])->toBe('hodl')->and($signal['reason'])->toBe('awaiting_price_displacement')
                ->and($signal['scoring']['signal_memory']['raw_confidence'])->toBe(0.85);
        }
        $previous = json_decode(json_encode($signal, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }
});

it('uses a mature causal EMA checkpoint identical to a single canonical indicator pass', function () {
    $market = signalMemoryMarket();
    $bars = signalMemoryBars($market, [...array_fill(0, 4, '2.005'), ...array_fill(0, 36, '2.000'), '1000.000']);
    $engine = new class { use Technical; };
    $expected = $bars;
    $key = $engine->atr($expected, 3, 'ema');
    $checkpoint = null;
    foreach (range(3, 39) as $index) {
        $actual = app(SignalMemoryAtr::class)->context($market->exchange->class, $market->symbol,
            '1m', $bars[$index]['microtimestamp'] + 60000, $checkpoint);
        expect(isset($actual['error']))->toBeFalse()
            ->and(bccomp($actual['atr'], $expected[$index][$key], 24))->toBe(0)
            ->and(bccomp($actual['previous_atr'], $expected[$index - 1][$key], 24))->toBe(0);
        $checkpoint = json_decode(json_encode($actual['checkpoint'], JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        expect(count($checkpoint['carry']))->toBeLessThanOrEqual(13);
    }
    // The extreme future candle was stored, but never entered an earlier context.
    expect(bccomp($actual['atr'], '0.040', 24))->toBe(0);
});

it('replays identical source proposals and cannot emit again when same-candle evidence changes', function () {
    $market = signalMemoryMarket();
    $bars = signalMemoryBars($market, ['2.005', '2.005', '2.005', '2.005', '1.950']);
    $filter = app(SignalMemoryFilter::class);
    $raw = signalMemoryProposal($market, $bars[4], reason: 'degraded_action_only');
    $first = $filter->apply($market, '1m', $raw, null);
    expect($first['action'])->toBe('buy')->and($first['reason'])->toBe('degraded_action_only');
    $replayed = $filter->apply($market, '1m', $raw, json_decode(json_encode($first), true));
    expect($replayed)->toEqual($first);
    $raw['confidence'] = 0.9;
    $changed = $filter->apply($market, '1m', $raw, $first);
    expect($changed['action'])->toBe('hodl')->and($changed['reason'])->toBe('signal_memory_candle_already_processed');
});

it('keeps a brief HOLD from releasing a pending action and resets on an opposite proposal', function () {
    $market = signalMemoryMarket();
    $bars = signalMemoryBars($market, ['2.005', '2.005', '2.005', '2.005', '2.000', '1.995', '1.990']);
    $filter = app(SignalMemoryFilter::class);
    $first = $filter->apply($market, '1m', signalMemoryProposal($market, $bars[4]), null);
    $hold = $filter->apply($market, '1m', signalMemoryProposal($market, $bars[5], 'hodl'), $first);
    expect($hold['action'])->toBe('hodl')->and($hold['reason'])->toBe('supported')
        ->and($hold['signal_memory_state']['anchor']['t0_ms'])->toBe($first['signal_memory_state']['anchor']['t0_ms']);
    $opposite = $filter->apply($market, '1m', signalMemoryProposal($market, $bars[6], 'sell'), $hold);
    expect($opposite['action'])->toBe('hodl')->and($opposite['signal_memory_state']['anchor']['direction'])->toBe('sell')
        ->and($opposite['signal_memory_state']['anchor']['t0_price'])->toBe('1.990');
});

it('does not accumulate across a candle gap even after ATR has warmed up again', function () {
    $market = signalMemoryMarket();
    $bars = signalMemoryBars($market, ['2.005', '2.005', '2.005', '2.005', '2.000', '1.985', '1.980',
        '1.970', '1.965', '1.960', '1.955'], [6]);
    $filter = app(SignalMemoryFilter::class);
    $first = $filter->apply($market, '1m', signalMemoryProposal($market, $bars[4]), null);
    $afterGap = $filter->apply($market, '1m', signalMemoryProposal($market, $bars[10]), $first);
    expect($afterGap['action'])->toBe('hodl')->and($afterGap['scoring']['signal_memory']['path'])->toBe('first_candidate')
        ->and($afterGap['signal_memory_state']['anchor']['t0_price'])->toBe('1.955');
});

it('waits on insufficient ATR history and on source-price disagreement', function () {
    $market = signalMemoryMarket();
    $bars = signalMemoryBars($market, ['2.005', '2.005', '2.005', '2.005', '2.000']);
    $filter = app(SignalMemoryFilter::class);
    $warmup = $filter->apply($market, '1m', signalMemoryProposal($market, $bars[2]), null);
    expect($warmup['reason'])->toBe('signal_memory_atr_unavailable');
    $raw = signalMemoryProposal($market, $bars[4]);
    $raw['reference_price'] = '1.000';
    $mismatch = $filter->apply($market, '1m', $raw, null);
    expect($mismatch['action'])->toBe('hodl')->and($mismatch['reason'])->toBe('signal_memory_source_mismatch');
});

it('resets pending references on upstream abstention or a model change', function () {
    $market = signalMemoryMarket();
    $bars = signalMemoryBars($market, ['2.005', '2.005', '2.005', '2.005', '2.000', '1.995']);
    $filter = app(SignalMemoryFilter::class);
    $first = $filter->apply($market, '1m', signalMemoryProposal($market, $bars[4]), null);
    $raw = signalMemoryProposal($market, $bars[5], 'hodl', 'stale_features');
    $stale = $filter->apply($market, '1m', $raw, $first);
    expect($stale['reason'])->toBe('stale_features')->and($stale['signal_memory_state']['anchor'])->toBeNull();
    $raw = signalMemoryProposal($market, $bars[5]);
    $raw['model_id'] = 'replacement-model';
    $replaced = $filter->apply($market, '1m', $raw, $first);
    expect($replaced['signal_memory_state']['anchor']['t0_price'])->toBe('1.995');
});

it('does not re-emit a candle already recorded before activation', function () {
    $market = signalMemoryMarket();
    $bars = signalMemoryBars($market, ['2.005', '2.005', '2.005', '2.005', '1.950']);
    $raw = signalMemoryProposal($market, $bars[4]);
    $result = app(SignalMemoryFilter::class)->apply($market, '1m', $raw, $raw);
    expect($result['action'])->toBe('hodl')->and($result['reason'])->toBe('signal_memory_candle_already_processed');
});

it('persists memory with the journal observation and deduplicates an unchanged upstream abstention', function () {
    $market = signalMemoryMarket();
    $first = app(SignalJournal::class)->record($market);
    expect($first->reason)->toBe('no_model')->and($first->payload['signal_memory_state']['market_id'])->toBe($market->getKey());
    $second = app(SignalJournal::class)->record($market);
    expect($second->getKey())->toBe($first->getKey());
    $this->assertDatabaseCount('market_signals', 1);
});

it('keeps CLI reads side-effect-free and keeps pending proposals HOLD-equivalent for clients', function () {
    $market = signalMemoryMarket();
    $bars = signalMemoryBars($market, ['2.005', '2.005', '2.005', '2.005', '2.000']);
    $payload = app(SignalMemoryFilter::class)->apply($market, '1m', signalMemoryProposal($market, $bars[4]), null);
    MarketSignal::query()->create(['market_id' => $market->getKey(), 'period' => '1m',
        'snapshot_key' => hash('sha256', $market->getKey()), 'model_id' => null,
        'decision_at_ms' => $payload['decision_at_ms'], 'recorded_at_ms' => now()->getTimestampMs(),
        'is_change' => true, 'action' => $payload['action'], 'reason' => $payload['reason'], 'payload' => $payload]);
    $status = Artisan::call('trademinator:signal', ['exchange' => $market->exchange->class,
        'symbol' => $market->symbol, 'period' => '1m']);
    $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($status)->toBe(0)->and($output['observation']['source'])->toBe('journal')
        ->and($output['observation']['read_only'])->toBeTrue()
        ->and(array_key_exists('signal_memory_state', $output))->toBeFalse()
        ->and($output['scoring']['signal_memory']['status'])->toBe('pending')
        ->and(SignalSemantics::clientAction($payload['action'], $payload['reason']))->toBe('hold');
    $this->assertDatabaseCount('market_signals', 1);
});

it('does not append an older recorder observation over a newer source watermark', function () {
    $market = signalMemoryMarket();
    $newer = app(SignalJournal::class)->record($market, now()->getTimestampMs());
    $older = app(SignalJournal::class)->record($market, now()->subMinute()->getTimestampMs());
    expect($older->getKey())->toBe($newer->getKey())
        ->and($older->payload['signal_memory_state']['last_processed_at_ms'])->toBe(now()->getTimestampMs());
    $this->assertDatabaseCount('market_signals', 1);
});

it('leaves the original recorder behavior unchanged when the rollout switch is off', function () {
    config(['signal_memory.enabled' => false]);
    $signal = app(SignalJournal::class)->record(signalMemoryMarket());
    expect($signal->reason)->toBe('no_model')
        ->and(array_key_exists('signal_memory_state', $signal->payload))->toBeFalse();
});
