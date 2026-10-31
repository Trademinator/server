<?php

namespace App\Domain\Intelligence;

use App\Domain\Operations\ActionLog;
use Closure;
use Generator;
use Illuminate\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use RuntimeException;

/** Shared durable state. A fenced transaction commits the cursor and its chunks together. */
final class BuildCheckpoint
{
    public const VERSION = 'labels-snapshots-v1';

    public const LEASE_SECONDS = 90;

    public array $state;

    private Lock $lock;

    private string $owner;

    private array $writers = [];

    private int $operations = 0;

    public readonly string $key;

    public function __construct(private array $identity, private ?float $deadline = null)
    {
        $this->key = hash('sha256', self::encode([self::VERSION, $identity]));
        $this->lock = Cache::lock('trademinator:checkpoint:'.$this->key, self::LEASE_SECONDS);
        if (! $this->lock->get()) {
            throw new BuildPaused('This build is already running on another worker.');
        }
        $this->owner = (string) Str::uuid7();
        TrainingRuntime::registerLease('checkpoint:'.$this->owner, fn (): bool => $this->lock->refresh(self::LEASE_SECONDS)
            || $this->lock->refresh(self::LEASE_SECONDS + 1));
        try {
            $this->state = DB::transaction(function (): array {
                $empty = self::encode([]);
                DB::table('knowledge_build_checkpoints')->insertOrIgnore([
                    'build_key' => $this->key, 'owner' => $this->owner,
                    'state' => $empty, 'sha256' => hash('sha256', $empty), 'updated_at' => now(),
                ]);
                $row = DB::table('knowledge_build_checkpoints')->where('build_key', $this->key)->lockForUpdate()->first();
                if (! hash_equals($row->sha256, hash('sha256', $row->state))) {
                    throw new RuntimeException('Build checkpoint checksum mismatch.');
                }
                if (! $this->lock->isOwnedByCurrentProcess()) {
                    throw new RuntimeException('Build checkpoint lease expired before ownership transfer.');
                }
                DB::table('knowledge_build_checkpoints')->where('build_key', $this->key)->update(['owner' => $this->owner]);

                return json_decode($row->state, true, flags: JSON_THROW_ON_ERROR);
            });
            if ($this->state !== []) {
                $this->log('resumed');
            }
        } catch (\Throwable $error) {
            $this->close();
            throw $error;
        }
    }

    public static function implementation(string $kind): string
    {
        $files = ['composer.lock', 'app/Domain/Intelligence/BuildCheckpoint.php',
            'app/Domain/Intelligence/ActionLabelCursor.php', 'app/Domain/Intelligence/ActionCandleBuffer.php',
            'app/Domain/Intelligence/LabelChunkWriter.php', 'app/Domain/Intelligence/AlgorithmicLabelSets.php',
            'app/Domain/MarketData/CandleProvenance.php', 'app/Domain/MarketData/CandleTimeframe.php',
            'app/Domain/Intelligence/AlgorithmicLabelBuilder.php',
            'app/Domain/Intelligence/ActionLabelAnalysis.php', 'app/Domain/Intelligence/ActionAutoLabeler.php',
            'app/Traits/CandleAutoDetection.php', 'app/Domain/Research/SemanticLabels.php'];
        if ($kind === 'snapshot') {
            $files = [...$files, 'app/Domain/Research/DatasetSnapshotBuilder.php',
                'app/Domain/Research/FeatureSchema.php', 'app/Domain/Intelligence/PatternCatalog.php'];
        }

        return hash('sha256', implode('', array_map(fn (string $file): string => hash_file('sha256', base_path($file)), $files)));
    }

    public static function encode(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    public function due(): bool
    {
        $this->operations++;

        // Restoring a large checkpoint can consume the slice. Always let the
        // first item run before yielding, or retries could make no progress.
        return $this->operations > 1
            && ($this->operations % max(1, (int) config('research.checkpoint_rows', 512)) === 0
                || ($this->deadline !== null && microtime(true) >= $this->deadline));
    }

    public function onSave(string $name, ?Closure $writer): void
    {
        if ($writer === null) {
            unset($this->writers[$name]);
        } else {
            $this->writers[$name] = $writer;
        }
    }

    public function save(bool $allowPause = true): void
    {
        TrainingRuntime::heartbeat(true);
        DB::transaction(function (): void {
            $this->assertOwner();
            foreach ($this->writers as $writer) {
                $writer();
            }
            $bytes = self::encode($this->state);
            DB::table('knowledge_build_checkpoints')->where('build_key', $this->key)->update([
                'state' => $bytes, 'sha256' => hash('sha256', $bytes), 'updated_at' => now(),
            ]);
        });
        // Normal work commits here; publication may enclose this in an outer transaction.
        Event::dispatch('knowledge.checkpoint.saved', [$this->key, $this->state]);
        if ($allowPause && $this->deadline !== null && microtime(true) >= $this->deadline) {
            $this->log('paused');
            throw new BuildPaused('Progress saved; this build will continue on the next worker attempt.');
        }
    }

    public function put(string $stream, int $chunk, string $bytes): void
    {
        DB::table('knowledge_build_checkpoint_chunks')->upsert([
            ['build_key' => $this->key, 'stream' => $stream, 'chunk' => $chunk,
                'payload' => $bytes, 'sha256' => hash('sha256', $bytes)],
        ], ['build_key', 'stream', 'chunk'], ['payload', 'sha256']);
    }

    public function chunks(string $stream): Generator
    {
        $expected = 0;
        foreach (DB::table('knowledge_build_checkpoint_chunks')->where('build_key', $this->key)
            ->where('stream', $stream)->orderBy('chunk')->lazyById(16, 'chunk') as $row) {
            if ((int) $row->chunk !== $expected++ || ! hash_equals($row->sha256, hash('sha256', $row->payload))) {
                throw new RuntimeException('Build checkpoint chunk is missing or corrupt.');
            }
            TrainingRuntime::heartbeat();
            yield $row->payload;
        }
    }

    public function clearStream(string $stream): void
    {
        DB::table('knowledge_build_checkpoint_chunks')->where('build_key', $this->key)->where('stream', $stream)->delete();
    }

    public function reset(): void
    {
        DB::transaction(function (): void {
            $this->assertOwner();
            DB::table('knowledge_build_checkpoint_chunks')->where('build_key', $this->key)->delete();
            $bytes = self::encode([]);
            DB::table('knowledge_build_checkpoints')->where('build_key', $this->key)
                ->update(['state' => $bytes, 'sha256' => hash('sha256', $bytes), 'updated_at' => now()]);
        });
        $this->state = [];
        $this->log('invalidated');
    }

    public function complete(): void
    {
        DB::transaction(function (): void {
            $this->assertOwner();
            DB::table('knowledge_build_checkpoints')->where('build_key', $this->key)->delete();
        });
        $this->log('completed');
    }

    private function assertOwner(): void
    {
        $owner = DB::table('knowledge_build_checkpoints')->where('build_key', $this->key)->lockForUpdate()->value('owner');
        if ($owner !== $this->owner || ! $this->lock->isOwnedByCurrentProcess()) {
            throw new RuntimeException('Build checkpoint ownership was lost; no progress was committed.');
        }
    }

    private function log(string $status): void
    {
        app(ActionLog::class)->write('knowledge.checkpoint.'.$status, [
            ...$this->identity, 'stage' => $this->state['stage'] ?? $this->identity['kind'],
            'processed' => $this->state['processed'] ?? 0, 'outcome' => $status,
        ]);
    }

    public function close(): void
    {
        $this->writers = [];
        TrainingRuntime::unregisterLease('checkpoint:'.$this->owner);
        $this->lock->release();
    }
}
