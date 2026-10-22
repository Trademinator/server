<?php

namespace App\Domain\Intelligence;

use App\Domain\Research\SemanticLabels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** Immutable, OHLCV-derived Action + Outcome labels, independently of Features. */
final class AlgorithmicLabelSets
{
    public const ACTION_VERSION = 'action-auto-label-v1';

    public function profile(string $exchange, string $symbol): string
    {
        return hash('sha256', json_encode([
            'action_version' => self::ACTION_VERSION,
            'outcome_version' => SemanticLabels::VERSION,
            'lookback' => (int) config('intelligence.lookback'),
            'minimum_pivot_distance_observations' => (int) config('intelligence.min_horizon_distance_observations'),
            'taker_fee' => app(PublishedTakerFee::class)->for($exchange, $symbol),
        ], JSON_THROW_ON_ERROR));
    }

    public function revision(string $exchange, string $symbol, string $period): int
    {
        return (int) (DB::table('market_history_backfills as history')
            ->join('markets', 'markets.market_id', '=', 'history.market_id')
            ->join('exchanges', 'exchanges.exchange_id', '=', 'markets.exchange_id')
            ->where('exchanges.class', $exchange)->where('markets.symbol', $symbol)
            ->where('history.period', $period)->max('history.history_revision') ?? 0);
    }

    public function find(string $exchange, string $symbol, string $period, int $asOfMs): ?array
    {
        $row = DB::table('algorithmic_label_sets')
            ->where(compact('exchange', 'symbol', 'period'))
            ->where('as_of_ms', $asOfMs)
            ->where('history_revision', $this->revision($exchange, $symbol, $period))
            ->where('action_version', self::ACTION_VERSION)
            ->where('outcome_version', SemanticLabels::VERSION)
            ->where('profile_sha256', $this->profile($exchange, $symbol))->first();
        if ($row === null) {
            return null;
        }

        $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
        if (! hash_equals($row->sha256, hash('sha256', $row->payload))) {
            throw new RuntimeException('Algorithmic label-set checksum mismatch.');
        }

        return [...$payload, 'label_set_id' => $row->label_set_id, 'label_set_sha256' => $row->sha256];
    }

    public function publish(string $exchange, string $symbol, string $period, int $asOfMs, array $payload): array
    {
        $revision = $this->revision($exchange, $symbol, $period);
        $profile = $this->profile($exchange, $symbol);
        $bytes = json_encode($payload, JSON_THROW_ON_ERROR);
        DB::table('algorithmic_label_sets')->insertOrIgnore([
            'label_set_id' => (string) Str::uuid7(),
            'exchange' => $exchange, 'symbol' => $symbol, 'period' => $period,
            'as_of_ms' => $asOfMs, 'history_revision' => $revision,
            'action_version' => self::ACTION_VERSION,
            'outcome_version' => SemanticLabels::VERSION, 'profile_sha256' => $profile,
            'sha256' => hash('sha256', $bytes), 'payload' => $bytes,
            'created_at' => now(),
        ]);

        return $this->find($exchange, $symbol, $period, $asOfMs)
            ?? throw new RuntimeException('Algorithmic label-set publication failed.');
    }
}
