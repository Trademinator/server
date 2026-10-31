<?php

namespace App\Domain\Intelligence;

use App\Domain\MarketData\HistoryReplayWindow;
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
            'training_history_policy' => KnowledgeWindow::TRAINING_POLICY,
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
        $revision = $this->revision($exchange, $symbol, $period);
        $row = DB::table('algorithmic_label_sets')
            ->where(compact('exchange', 'symbol', 'period'))
            ->where('as_of_ms', $asOfMs)
            ->where('history_revision', '<=', $revision)
            ->where('action_version', self::ACTION_VERSION)
            ->where('outcome_version', SemanticLabels::VERSION)
            ->where('profile_sha256', $this->profile($exchange, $symbol))
            ->orderByDesc('history_revision')->first();
        if ($row === null || ! $this->unchangedThrough($exchange, $symbol, $period,
            $asOfMs, (int) $row->history_revision, $revision)) {
            return null;
        }

        $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
        if (! hash_equals($row->sha256, hash('sha256', $row->payload))) {
            throw new RuntimeException('Algorithmic label-set checksum mismatch.');
        }

        if (($payload['label_storage'] ?? null) === 'chunks-v1') {
            foreach (LabelChunkWriter::KINDS as $kind) {
                $map = new AlgorithmicLabelMap($row->label_set_id, $kind, $payload['label_chunks'][$kind]);
                $map->verify();
                $payload[$kind] = $map;
            }
        }

        return [...$payload, 'label_set_id' => $row->label_set_id, 'label_set_sha256' => $row->sha256];
    }

    /** Only a complete change ledger can prove that newer revisions are outside this frozen input. */
    public function unchangedThrough(string $exchange, string $symbol, string $period,
        int $asOfMs, int $fromRevision, int $toRevision): bool
    {
        if ($fromRevision === $toRevision) {
            return true;
        }
        if ($fromRevision < 0 || $fromRevision > $toRevision) {
            return false;
        }

        $histories = DB::table('market_history_backfills as history')
            ->join('markets', 'markets.market_id', '=', 'history.market_id')
            ->join('exchanges', 'exchanges.exchange_id', '=', 'markets.exchange_id')
            ->where('exchanges.class', $exchange)->where('markets.symbol', $symbol)
            ->where('history.period', $period)->pluck('history.history_id');
        if ($histories->count() !== 1) {
            return false;
        }

        $changes = DB::table('market_history_changes')->where('history_id', $histories->first())
            ->where('revision', '>', $fromRevision)->where('revision', '<=', $toRevision)
            ->select(['revision', 'from_ms', 'to_ms'])->lazyById(256, 'revision');
        $earliest = HistoryReplayWindow::earliest($fromRevision, $toRevision, $changes);

        // streamHistory includes the cutoff timestamp; a correction exactly there still invalidates it.
        return $earliest !== null && $earliest > $asOfMs;
    }

    public function publish(string $exchange, string $symbol, string $period, int $asOfMs, array $payload): array
    {
        $writer = new LabelChunkWriter;
        foreach (LabelChunkWriter::KINDS as $kind) {
            $rows = $payload[$kind] ?? [];
            ksort($rows, SORT_NUMERIC);
            foreach ($rows as $timestamp => $value) {
                $writer->append($kind, (int) $timestamp, $value);
            }
            unset($payload[$kind]);
        }

        return $this->publishChunks($exchange, $symbol, $period, $asOfMs, $payload, $writer);
    }

    public function publishChunks(string $exchange, string $symbol, string $period, int $asOfMs,
        array $payload, LabelChunkWriter $writer, ?int $expectedRevision = null): array
    {
        $revision = $this->revision($exchange, $symbol, $period);
        if ($expectedRevision !== null && ! $this->unchangedThrough($exchange, $symbol, $period,
            $asOfMs, $expectedRevision, $revision)) {
            throw new RuntimeException('Candle history changed during label generation; retry the build.');
        }
        $profile = $this->profile($exchange, $symbol);
        $payload['label_storage'] = 'chunks-v1';
        $payload['label_chunks'] = $writer->manifest();
        $bytes = json_encode($payload, JSON_THROW_ON_ERROR);
        DB::transaction(function () use ($exchange, $symbol, $period, $asOfMs, $revision, $profile, $bytes, $writer): void {
            $id = (string) Str::uuid7();
            $inserted = DB::table('algorithmic_label_sets')->insertOrIgnore([
                'label_set_id' => $id,
                'exchange' => $exchange, 'symbol' => $symbol, 'period' => $period,
                'as_of_ms' => $asOfMs, 'history_revision' => $revision,
                'action_version' => self::ACTION_VERSION,
                'outcome_version' => SemanticLabels::VERSION, 'profile_sha256' => $profile,
                'sha256' => hash('sha256', $bytes), 'payload' => $bytes,
                'created_at' => now(),
            ]);
            if ($inserted === 0) {
                return;
            }
            foreach ($writer->records() as $record) {
                TrainingRuntime::heartbeat();
                DB::table('algorithmic_label_chunks')->insert(['label_set_id' => $id, ...$record]);
            }
        });

        return $this->find($exchange, $symbol, $period, $asOfMs)
            ?? throw new RuntimeException('Algorithmic label-set publication failed.');
    }
}
