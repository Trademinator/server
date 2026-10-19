<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Client\ClientMarketAccess;
use App\Domain\Client\ReplayIntelligence;
use App\Domain\Intelligence\ModelStore;
use App\Domain\MarketData\CandleTimeframe;
use App\Http\Controllers\Controller;
use App\Models\Market;
use App\Models\MarketSignal;
use App\Repositories\TickerRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ReplayController extends Controller
{
    private function periods(): array
    {
        return array_values(array_filter(CandleTimeframe::SUPPORTED, fn (string $p): bool => ! str_ends_with($p, 'M') && ! str_ends_with($p, 'y')));
    }

    public function history(Request $request, string $subscription, ClientMarketAccess $access, TickerRepository $tickers): JsonResponse
    {
        $item = $access->owned($request->user(), $subscription);
        $data = $request->validate([
            'source_exchange' => ['required', 'string', 'regex:/^[a-z][a-z0-9]{1,40}$/D'],
            'period' => ['required', Rule::in($this->periods())],
            'from_ms' => ['required', 'integer', 'min:0'], 'to_ms' => ['required', 'integer', 'gte:from_ms', 'max:'.now()->getTimestampMs()],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:500'],
        ]);
        $limit = (int) ($data['limit'] ?? 500);
        $timeframe = new CandleTimeframe;
        $candles = [];
        $gaps = [];
        $expected = (int) $data['from_ms'];
        $next = null;
        foreach ($tickers->streamHistory($data['source_exchange'], $item->market->symbol, $data['period'], (int) $data['from_ms'], (int) $data['to_ms']) as $timestamp => $row) {
            if ($timeframe->next((int) $timestamp, $data['period']) > $data['to_ms']) {
                break;
            }
            if (count($candles) === $limit) {
                $next = (int) $timestamp;
                break;
            }
            if ($timestamp > $expected) {
                $gaps[] = ['from_ms' => $expected, 'to_ms' => (int) $timestamp, 'verified' => false];
            }
            $candles[] = [(int) $timestamp, (string) $row['open'], (string) $row['high'], (string) $row['low'], (string) $row['close'], (string) $row['volume']];
            $expected = $timeframe->next((int) $timestamp, $data['period']);
        }
        if ($next === null && $expected < $data['to_ms']) {
            $gaps[] = ['from_ms' => $expected, 'to_ms' => (int) $data['to_ms'], 'verified' => false];
        }
        $source = Market::query()->where('symbol', $item->market->symbol)->whereHas('exchange', fn ($q) => $q->where('class', $data['source_exchange']))->first();
        $signals = [];
        if ($source !== null) {
            $query = MarketSignal::query()->where('market_id', $source->getKey())->where('period', $data['period']);
            $before = (clone $query)->where('recorded_at_ms', '<', $data['from_ms'])->orderByDesc('recorded_at_ms')->orderByDesc('market_signal_id')->first();
            $records = (clone $query)->whereBetween('recorded_at_ms', [$data['from_ms'], $next ?? $data['to_ms']])->orderBy('recorded_at_ms')->orderBy('market_signal_id')->limit(2000)->get();
            if ($before !== null) {
                $records->prepend($before);
            }
            foreach ($records as $signal) {
                $signals[] = ['id' => $signal->getKey(), 'action' => $signal->action, 'reason' => $signal->reason,
                    'decision_at_ms' => (int) $signal->decision_at_ms, 'recorded_at_ms' => (int) $signal->recorded_at_ms, 'model_id' => $signal->model_id];
            }
        }

        return response()->json(['api_version' => 1, 'source_exchange' => $data['source_exchange'], 'symbol' => $item->market->symbol,
            'period' => $data['period'], 'candles' => $candles, 'signals' => $signals, 'next_from_ms' => $next,
            'gaps' => $gaps, 'execution_detail' => 'ohlcv_approximate']);
    }

    public function models(Request $request, string $subscription, ClientMarketAccess $access): JsonResponse
    {
        $item = $access->owned($request->user(), $subscription);
        $data = $request->validate(['model_exchange' => ['required', 'string', 'regex:/^[a-z][a-z0-9]{1,40}$/D'],
            'period' => ['required', Rule::in($this->periods())]]);
        $key = ModelStore::marketKey($data['model_exchange'], $item->market->symbol, $data['period']);
        $models = [];
        $head = DB::table('intelligence_heads')->where('market_key', $key)->value('model_id');
        foreach (DB::table('intelligence_models')->where('market_key', $key)->orderByDesc('created_at')->orderBy('model_id')->limit(100)->get() as $m) {
            $report = json_decode($m->report, true);
            $models[] = ['model_id' => $m->model_id, 'family' => 'production', 'algorithm' => 'production_fusion',
                'current' => $m->model_id === $head, 'published_at_ms' => strtotime($m->created_at) * 1000,
                'trained_until_ms' => $report['trained_as_of_ms'] ?? null, 'sha256' => $m->sha256, 'status' => $m->status];
        }
        foreach (DB::table('tournament_shadow_models')->where('market_key', $key)->orderByDesc('published_at_ms')->orderBy('shadow_model_id')->limit(100)->get() as $m) {
            $models[] = ['model_id' => $m->shadow_model_id, 'family' => 'shadow', 'algorithm' => $m->algorithm, 'task' => $m->task,
                'published_at_ms' => (int) $m->published_at_ms, 'trained_until_ms' => (int) $m->trained_until_ms, 'sha256' => $m->sha256];
        }

        return response()->json(['api_version' => 1, 'symbol' => $item->market->symbol, 'models' => $models]);
    }

    public function infer(Request $request, string $subscription, ClientMarketAccess $access, ReplayIntelligence $replay): JsonResponse
    {
        $item = $access->owned($request->user(), $subscription);
        abort_if(strlen($request->getContent()) > 16777216, 413, 'Replay payload exceeds 16 MiB.');
        $data = $request->validate([
            'source_exchange' => ['required', 'string', 'regex:/^[a-z][a-z0-9]{1,40}$/D'],
            'model_exchange' => ['required', 'string', 'regex:/^[a-z][a-z0-9]{1,40}$/D'],
            'period' => ['required', Rule::in($this->periods())], 'model_id' => ['required', 'uuid'],
            'family' => ['required', Rule::in(['production', 'shadow'])], 'intelligence' => ['required', Rule::in(['historical', 'current'])],
            'decision_at_ms' => ['required', 'integer', 'min:1', 'max:'.now()->getTimestampMs()],
            'candles' => ['required', 'array', 'list', 'min:1', 'max:50000'],
        ]);
        foreach ($data['candles'] as $row) {
            if (! is_array($row) || ! array_is_list($row) || count($row) !== 6 || ! is_int($row[0]) || $row[0] < 0) {
                throw ValidationException::withMessages(['candles' => 'Each candle needs an integer timestamp and five numeric fields.']);
            }
            foreach (array_slice($row, 1) as $i => $value) {
                if (! is_numeric($value) || ! is_finite((float) $value) || ($i === 4 ? $value < 0 : $value <= 0)) {
                    throw ValidationException::withMessages(['candles' => 'Candle prices must be positive and volume nonnegative.']);
                }
            }
            if ((float) $row[3] > min((float) $row[1], (float) $row[4]) || (float) $row[2] < max((float) $row[1], (float) $row[4])) {
                throw ValidationException::withMessages(['candles' => 'Invalid OHLCV bounds.']);
            }
        }
        try {
            return response()->json($replay->infer($data, $item->market->symbol));
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['candles' => $e->getMessage()]);
        }
    }
}
