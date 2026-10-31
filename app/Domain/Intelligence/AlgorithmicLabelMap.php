<?php

namespace App\Domain\Intelligence;

use ArrayAccess;
use Countable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use IteratorAggregate;
use LogicException;
use RuntimeException;
use Traversable;

/**
 * Read-only timestamp map. Dataset iteration holds at most one label chunk.
 *
 * @implements ArrayAccess<int, mixed>
 * @implements IteratorAggregate<int, mixed>
 */
final class AlgorithmicLabelMap implements ArrayAccess, Countable, IteratorAggregate
{
    private array $cache = [];

    private int $first = -1;

    private int $last = -1;

    /** @param array{rows: int, chunks: int, sha256: string} $manifest */
    public function __construct(private string $setId, private string $kind, private array $manifest) {}

    public function verify(): void
    {
        $hash = hash_init('sha256');
        $count = $chunks = 0;
        $last = -1;
        foreach ($this->query()->select(['chunk', 'first_ms', 'last_ms', 'row_count', 'sha256'])
            ->lazyById(256, 'chunk') as $row) {
            if ((int) $row->chunk !== $chunks || (int) $row->first_ms <= $last
                || (int) $row->last_ms < (int) $row->first_ms
                || (int) $row->row_count < 1 || (int) $row->row_count > LabelChunkWriter::ROWS_PER_CHUNK) {
                throw new RuntimeException('Invalid algorithmic label chunk index.');
            }
            hash_update($hash, LabelChunkWriter::fingerprint((array) $row));
            $count += (int) $row->row_count;
            $last = (int) $row->last_ms;
            $chunks++;
        }
        if ($count !== $this->manifest['rows'] || $chunks !== $this->manifest['chunks']
            || ! hash_equals($this->manifest['sha256'], hash_final($hash))) {
            throw new RuntimeException('Algorithmic label chunk manifest mismatch.');
        }
    }

    public function count(): int
    {
        return $this->manifest['rows'];
    }

    public function offsetExists(mixed $offset): bool
    {
        return $this->offsetGet($offset) !== null;
    }

    public function offsetGet(mixed $offset): mixed
    {
        if (! is_int($offset) || $offset < 0) {
            return null;
        }
        if ($offset < $this->first || $offset > $this->last) {
            $row = $this->query()->where('last_ms', '>=', $offset)->orderBy('last_ms')->first();
            $this->cache = $row === null ? [] : $this->decode($row);
            $this->first = $row === null ? $offset : min($offset, (int) $row->first_ms);
            $this->last = $row === null ? PHP_INT_MAX : (int) $row->last_ms;
        }

        return $this->cache[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException('Published algorithmic labels are immutable.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('Published algorithmic labels are immutable.');
    }

    public function getIterator(): Traversable
    {
        $count = 0;
        foreach ($this->query()->lazyById(1, 'chunk') as $chunk) {
            foreach ($this->decode($chunk) as $timestamp => $value) {
                $count++;
                yield $timestamp => $value;
            }
        }
        if ($count !== $this->manifest['rows']) {
            throw new RuntimeException('Algorithmic label stream is incomplete.');
        }
    }

    private function query(): Builder
    {
        return DB::table('algorithmic_label_chunks')->where('label_set_id', $this->setId)->where('kind', $this->kind);
    }

    private function decode(object $chunk): array
    {
        if (! hash_equals($chunk->sha256, hash('sha256', $chunk->payload))) {
            throw new RuntimeException('Algorithmic label chunk checksum mismatch.');
        }
        $rows = json_decode($chunk->payload, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($rows) || count($rows) !== (int) $chunk->row_count
            || array_key_first($rows) !== (int) $chunk->first_ms || array_key_last($rows) !== (int) $chunk->last_ms) {
            throw new RuntimeException('Algorithmic label chunk bounds mismatch.');
        }

        return $rows;
    }
}
