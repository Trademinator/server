<?php

namespace App\Domain\Intelligence;

use App\Domain\Features\FeatureEngine;
use App\Domain\MarketData\CandleTimeframe;
use App\Domain\Research\DatasetStore;
use App\Domain\Research\FeatureSchema;
use App\Domain\Research\SemanticLabels;
use App\Models\MarketFeature;
use App\Models\User;
use App\Repositories\TickerRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final class IndependentHumanAnnotations
{
    private const SOURCES = ['human_action', 'human_outcome'];

    public function __construct(private TickerRepository $tickers, private CandleTimeframe $timeframe, private DatasetStore $datasets) {}

    /** Save an opinion about a closed OHLCV candle; never require M2 features. */
    public function save(string $trainerId, string $exchange, string $symbol, string $period,
        string $source, int $timestamp, string $label): string
    {
        $allowed = match ($source) {
            'human_action' => CandleTraining::ACTIONS,
            'human_outcome' => HumanTraining::LABELS,
            default => throw new InvalidArgumentException('Unknown human labeling source.'),
        };
        if (! in_array($label, $allowed, true) || $timestamp < 1
            || $this->timeframe->next($timestamp, $period) > now()->getTimestampMs()) {
            throw new InvalidArgumentException('Label requires a valid closed candle and source.');
        }
        $candle = $this->sourceCandle($exchange, $symbol, $period, $timestamp);
        if ($source === 'human_action') {
            $movement = bccomp((string) $candle['close'], (string) $candle['open'], 12);
            if (($label === 'buy' && $movement >= 0) || ($label === 'sell' && $movement <= 0)) {
                throw new InvalidArgumentException('BUY requires a declining candle; SELL requires a rising candle.');
            }
        }
        $digest = self::candleDigest($candle);
        $scope = compact('trainerId', 'exchange', 'symbol', 'period');
        unset($scope['trainerId']);
        $scope['trainer_id'] = $trainerId;
        $scope['source'] = $source;
        $scope['microtimestamp'] = $timestamp;
        $scope['candle_sha256'] = $digest;
        $id = (string) Str::uuid7();
        DB::table('independent_human_annotations')->upsert([[
            'annotation_id' => $id, ...$scope, 'label' => $label, 'recorded_at' => now(),
        ]], ['trainer_id', 'exchange', 'symbol', 'period', 'source', 'microtimestamp', 'candle_sha256'],
            ['label', 'recorded_at']);

        return (string) DB::table('independent_human_annotations')->where($scope)->value('annotation_id');
    }

    /** Validate against the current dataset candle, then project to current features. */
    public function opinions(array $manifest, string $source, int $annotationCutoffMs, float $deadline): array
    {
        if (! in_array($source, self::SOURCES, true)) {
            throw new InvalidArgumentException('Unsupported annotation source.');
        }
        [, $rows] = $this->datasets->open($manifest['dataset_id']);
        $authorizedIds = array_map('strtolower', array_filter([
            ...User::ownerIds(), ...config('human_training.trainer_uuids'),
        ]));
        $authorized = User::query()->whereIn('user_id', $authorizedIds)->get()
            ->filter(fn (User $user): bool => Gate::forUser($user)->allows('train-intelligence'))
            ->pluck('user_id')->all();
        if ($authorized === []) {
            return [];
        }
        $scope = ['exchange' => $manifest['exchange'], 'symbol' => $manifest['symbol'],
            'period' => $manifest['period'], 'source' => $source];
        $records = DB::table('independent_human_annotations')->where($scope)
            ->whereIn('trainer_id', $authorized)
            ->where('recorded_at', '<=', \Carbon\CarbonImmutable::createFromTimestampMs($annotationCutoffMs)->toDateTimeString())
            ->orderBy('microtimestamp')->cursor();
        $grouped = [];
        foreach ($records as $record) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Human annotation projection exceeded its time budget.');
            }
            $grouped[(int) $record->microtimestamp][] = $record;
        }
        $result = [];
        foreach ($grouped as $timestamp => $opinions) {
            $decision = $this->timeframe->next($timestamp, $manifest['period']);
            $row = $rows->findDecision($decision);
            if ($row === null || ! isset($row['candle']) || ! isset($row['source']['feature_sha256'])) {
                continue;
            }
            $record = MarketFeature::query()->where('exchange', $manifest['exchange'])
                ->where('symbol', $manifest['symbol'])->where('period', $manifest['period'])
                ->where('version', FeatureEngine::VERSION)->where('microtimestamp', $timestamp)->first();
            if ($record === null || hash('sha256', json_encode($record->payload, JSON_THROW_ON_ERROR))
                !== $row['source']['feature_sha256']) {
                continue;
            }
            $labels = [];
            $annotators = [];
            $recorded = 0;
            $digest = self::candleDigest($row['candle']);
            foreach ($opinions as $opinion) {
                if ($opinion->candle_sha256 !== $digest) {
                    continue;
                }
                $annotators[$opinion->trainer_id] = $opinion->label;
                $recorded = max($recorded, \Carbon\CarbonImmutable::parse($opinion->recorded_at)->getTimestampMs());
            }
            foreach ($annotators as $label) {
                $labels[$label] = ($labels[$label] ?? 0) + 1;
            }
            arsort($labels);
            $count = count($annotators);
            $top = reset($labels) ?: 0;
            $second = array_values($labels)[1] ?? 0;
            if ($count < (int) config('human_training.min_reviewers')
                || $top / max(1, $count) < (float) config('human_training.min_agreement')
                || $top === $second) {
                continue;
            }
            $vector = FeatureSchema::vector($record->payload, $manifest['keys']);
            if ($vector === null) {
                continue;
            }
            $label = array_key_first($labels);
            $result[] = [
                'decision_at_ms' => $decision,
                'label_available_at_ms' => $source === 'human_action'
                    ? max($decision + 1, (int) ($row['action_label_available_at_ms'] ?? ($decision + 1)))
                    : (int) $row['label_available_at_ms'],
                'updated_at_ms' => $recorded,
                'vector' => NormalizedVector::from($vector, $manifest['keys']),
                'label' => $source === 'human_action' && $label === 'hold' ? 'hodl' : $label,
                'semantic_bottom' => $label === 'buy',
                'semantic_top' => $label === 'sell',
                'provenance' => ['source' => 'independent_ohlcv', 'candle_sha256' => $digest,
                    'trainer_count' => $count],
            ];
        }

        return $result;
    }

    public static function candleDigest(array $candle): string
    {
        $values = [];
        foreach (['open', 'high', 'low', 'close', 'volume'] as $field) {
            if (! is_numeric($candle[$field] ?? null)) {
                throw new InvalidArgumentException('Cannot label invalid OHLCV.');
            }
            $values[$field] = (string) $candle[$field];
        }

        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
    }

    private function sourceCandle(string $exchange, string $symbol, string $period, int $timestamp): array
    {
        foreach ($this->tickers->streamHistory($exchange, $symbol, $period, $timestamp, $timestamp) as $bar) {
            if ((int) ($bar['microtimestamp'] ?? 0) === $timestamp) {
                return $bar;
            }
        }
        throw new InvalidArgumentException('The requested OHLCV candle does not exist.');
    }
}
