<?php

namespace App\Http\Controllers\Markets;

use App\Domain\Intelligence\IndependentHumanAnnotations;
use App\Domain\MarketData\CandleTimeframe;
use App\Http\Controllers\Controller;
use App\Models\Market;
use App\Repositories\TickerRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class HumanAnnotationController extends Controller
{
    public function show(Request $request, Market $market, TickerRepository $tickers): Response
    {
        Gate::authorize('train-intelligence');
        abort_unless($market->subscriptions()->where('user_id', $request->user()->user_id)->exists()
            || Gate::allows('manage-server'), 403);
        $market->loadMissing('exchange', 'feed');
        $period = $market->feed?->selected_period;
        abort_if($period === null, 404);
        $timestamp = $request->integer('candle');
        if ($timestamp <= 0) {
            $timestamp = $tickers->latestTimestamp($market->exchange->class, $market->symbol, $period);
        }
        abort_if($timestamp === null, 404);
        $timeframe = new CandleTimeframe;
        if ($timeframe->next($timestamp, $period) > now()->getTimestampMs()) {
            $timestamp = $timeframe->previous($timestamp, $period);
        }
        $candle = null;
        foreach ($tickers->streamHistory($market->exchange->class, $market->symbol, $period, $timestamp, $timestamp) as $bar) {
            $candle = $bar;
        }
        abort_if($candle === null, 404);
        $from = $timestamp;
        for ($i = 1; $i < 25; $i++) {
            $from = max(0, $timeframe->previous($from, $period));
        }
        $history = iterator_to_array($tickers->streamHistory($market->exchange->class,
            $market->symbol, $period, $from, $timestamp), false);

        return response()->view('markets.ohlcv-annotation', [
            'history' => $history, 'market' => $market, 'period' => $period, 'timestamp' => $timestamp,
            'candle' => $candle, 'previous' => $timeframe->previous($timestamp, $period),
            'next' => $timeframe->next($timestamp, $period),
        ]);
    }

    public function store(Request $request, Market $market, IndependentHumanAnnotations $annotations): RedirectResponse
    {
        Gate::authorize('train-intelligence');
        abort_unless($market->subscriptions()->where('user_id', $request->user()->user_id)->exists()
            || Gate::allows('manage-server'), 403);
        $input = $request->validate([
            'source' => ['required', Rule::in(['human_action', 'human_outcome'])],
            'timestamp' => ['required', 'integer', 'min:1'],
            'label' => ['required', 'string', 'max:40'],
        ]);
        $market->loadMissing('exchange', 'feed');
        abort_if($market->feed?->selected_period === null, 404);
        try {
            $annotations->save((string) $request->user()->user_id,
                $market->exchange->class, $market->symbol, $market->feed->selected_period,
                $input['source'], (int) $input['timestamp'], $input['label']);
        } catch (\InvalidArgumentException $error) {
            throw ValidationException::withMessages(['label' => $error->getMessage()]);
        }
        return redirect()->route('human-training.ohlcv.show', ['market' => $market->market_id, 'candle' => $input['timestamp']])
            ->with('status', 'OHLCV opinion saved independently of feature generation.');
    }
}
