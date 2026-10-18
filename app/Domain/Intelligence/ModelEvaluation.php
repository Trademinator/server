<?php

namespace App\Domain\Intelligence;

use App\Domain\MarketData\CandleTimeframe;
use App\Repositories\TickerRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ModelEvaluation
{
    public function __construct(
        private TickerRepository $tickers,
        private CandleTimeframe $timeframe,
    ) {}

    public function list(bool $owner, string $userId): array
    {
        $query = DB::table('intelligence_models as im')
            ->join('intelligence_heads as ih', 'ih.model_id', '=', 'im.model_id')
            ->orderByDesc('im.created_at')->limit(100);
        $models = [];
        foreach ($query->get(['im.model_id', 'im.report', 'im.created_at']) as $item) {
            $report = json_decode($item->report, true);
            if (! is_array($report) || ! $this->canSee($owner, $userId, $report)) {
                continue;
            }
            $models[] = ['id' => $item->model_id, 'report' => $report, 'created' => $item->created_at];
        }

        return $models;
    }

    public function report(string $id, bool $owner, string $userId): array
    {
        abort_unless(Str::isUuid($id), 404);
        $record = DB::table('intelligence_models')->where('model_id', $id)->first(['report']);
        abort_if($record === null, 404);
        $report = json_decode($record->report, true, flags: JSON_THROW_ON_ERROR);
        abort_unless($this->canSee($owner, $userId, $report), 404);

        return $report;
    }

    private function canSee(bool $owner, string $userId, array $report): bool
    {
        if ($owner) {
            return true;
        }

        return DB::table('market_subscriptions as ms')
            ->join('markets as m', 'm.market_id', '=', 'ms.market_id')
            ->join('exchanges as e', 'e.exchange_id', '=', 'm.exchange_id')
            ->where('ms.user_id', $userId)
            ->where('ms.active', true)
            ->where('e.class', $report['exchange'] ?? '')
            ->where('m.symbol', $report['symbol'] ?? '')
            ->exists();
    }

    public function tournament(string $datasetId): ?array
    {
        $record = DB::table('intelligence_tournaments')->where('dataset_id', $datasetId)->value('report');
        return $record === null ? null : json_decode($record, true, flags: JSON_THROW_ON_ERROR);
    }

    public function series(array $report, ?array $tournament = null): array
    {
        $action = $report['action']['algorithmic']['holdout']['trace'] ?? [];
        $outcome = $report['outcome']['algorithmic']['holdout']['trace'] ?? [];
        $extra = [];
        foreach (['action', 'outcome'] as $task) {
            foreach (($tournament['tasks'][$task]['candidates'] ?? []) as $name => $candidate) {
                $extra[$task][$name] = $candidate['trace'] ?? [];
            }
        }
        $period = (string) ($report['period'] ?? '');
        if (($action === [] && $outcome === [] && $extra === []) || ! in_array($period, CandleTimeframe::SUPPORTED, true)) {
            return ['candles' => [], 'action' => [], 'outcome' => []];
        }
        $all = [...$action, ...$outcome];
        foreach ($extra as $task) foreach ($task as $trace) $all = [...$all, ...$trace];
        $times = array_column($all, 'decision_at_ms');
        if ($times === []) {
            return ['candles' => [], 'action' => [], 'outcome' => []];
        }
        $start = min($times);
        $end = max($times);
        $from = $this->timeframe->previous($start, $period);
        $candles = [];
        $indexed = [];
        foreach ($this->tickers->streamHistory(
            (string) $report['exchange'], (string) $report['symbol'], $period, $from, $end
        ) as $timestamp => $payload) {
            $timestamp = (int) $timestamp;
            if (! is_array($payload)) {
                continue;
            }
            $valid = true;
            foreach (['open', 'high', 'low', 'close'] as $field) {
                if (! isset($payload[$field]) || ! is_numeric($payload[$field])) {
                    $valid = false;
                    break;
                }
            }
            if (! $valid) {
                continue;
            }
            $bar = ['time' => intdiv($timestamp, 1000)];
            foreach (['open', 'high', 'low', 'close'] as $field) {
                $bar[$field] = (float) $payload[$field];
            }
            $indexed[$timestamp] = true;
            $candles[] = $bar;
            // A bounded chart; never transmit unbounded market history.
            if (count($candles) >= 6000) {
                break;
            }
        }
        $markers = static function (array $trace) use ($period, $indexed): array {
            $timeframe = app(CandleTimeframe::class);
            $markers = [];
            foreach ($trace as $record) {
                $stamp = (int) ($record['decision_at_ms'] ?? 0);
                $previous = $timeframe->previous($stamp, $period);
                if (! isset($indexed[$previous])) {
                    continue;
                }
                $markers[] = [
                    'time' => intdiv($previous, 1000),
                    'predicted' => $record['predicted'] ?? null,
                    'actual' => $record['actual'] ?? null,
                    'supported' => (bool) ($record['supported'] ?? false),
                    'confidence' => (float) ($record['confidence'] ?? 0.0),
                    'reason' => $record['reason'] ?? 'unknown',
                ];
            }

            return $markers;
        };

        $candidateMarkers = [];
        foreach ($extra as $task => $candidates) {
            foreach ($candidates as $name => $trace) {
                $candidateMarkers[$task][$name] = $markers($trace);
            }
        }
        return ['candles' => $candles, 'action' => $markers($action), 'outcome' => $markers($outcome),
            'candidates' => $candidateMarkers];
    }
}
