<?php

namespace App\Domain\Intelligence;

use ArrayAccess;
use Closure;
use Countable;
use Generator;
use InvalidArgumentException;
use IteratorAggregate;
use LogicException;
use OutOfBoundsException;
use RuntimeException;

/**
 * Request-local training records and immutable views with disk-backed indexes.
 *
 * @implements ArrayAccess<int, array<string, mixed>>
 * @implements IteratorAggregate<int, array<string, mixed>>
 */
final class TrainingRows implements ArrayAccess, Countable, IteratorAggregate
{
    private const INDEX_BYTES = 16;

    private const INDEX_BLOCK_ROWS = 256;

    private const READ_BLOCK_BYTES = 1048576;

    /** @param Closure(array<string, mixed>): array<string, mixed>|null $projection */
    private function __construct(
        private TrainingRowFile $records,
        private TrainingRowFile $index,
        private int $size,
        private int $offset = 0,
        private ?Closure $projection = null,
    ) {}

    /** @param iterable<array<string, mixed>> $rows */
    public static function from(iterable $rows): self
    {
        if ($rows instanceof self) {
            return $rows;
        }

        $records = TrainingRowFile::create();
        $index = TrainingRowFile::create();
        $count = $offset = 0;
        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new InvalidArgumentException('Training records must be arrays.');
            }
            $bytes = self::encode($row);
            $length = strlen($bytes);
            $records->append($bytes);
            $index->append(pack('J2', $offset, $length));
            $offset += $length;
            $count++;
        }

        return new self($records, $index, $count);
    }

    public function count(): int
    {
        return $this->size;
    }

    /** @return array<string, mixed> */
    public function at(int $position): array
    {
        if ($position < 0 || $position >= $this->size) {
            throw new OutOfBoundsException('Training row index is outside the selected records.');
        }

        $entry = unpack('Joffset/Jlength', $this->entryBytes($position));

        return $this->decode($this->records->read($entry['offset'], $entry['length']));
    }

    /** @param Closure(array<string, mixed>, int): bool $predicate */
    public function filter(Closure $predicate): self
    {
        $index = TrainingRowFile::create();
        $count = 0;
        foreach ($this as $position => $row) {
            if ($predicate($row, $position)) {
                // Store base-record locations so chained views never walk parent indexes.
                $index->append($this->entryBytes($position));
                $count++;
            }
        }

        return new self($this->records, $index, $count, projection: $this->projection);
    }

    public function slice(int $offset, ?int $length = null): self
    {
        $offset = $offset < 0 ? max(0, $this->size + $offset) : min($offset, $this->size);
        $remaining = $this->size - $offset;
        $length = $length === null ? $remaining
            : ($length < 0 ? max(0, $remaining + $length) : min($length, $remaining));

        return new self($this->records, $this->index, $length, $this->offset + $offset, $this->projection);
    }

    /** @param Closure(array<string, mixed>): array<string, mixed> $projection */
    public function map(Closure $projection): self
    {
        $previous = $this->projection;
        $combined = static function (array $row) use ($previous, $projection): array {
            $result = $projection($previous === null ? $row : $previous($row));
            if (! is_array($result)) {
                throw new InvalidArgumentException('Training row projections must return arrays.');
            }

            return $result;
        };

        return new self($this->records, $this->index, $this->size, $this->offset, $combined);
    }

    public function max(string $field, int|float $default = 0): int|float
    {
        $maximum = null;
        foreach ($this as $row) {
            if (! array_key_exists($field, $row)) {
                continue;
            }
            $value = $row[$field];
            if (! is_int($value) && ! is_float($value)) {
                throw new InvalidArgumentException('Training row maximum requires a numeric field.');
            }
            $maximum = $maximum === null ? $value : max($maximum, $value);
        }

        return $maximum ?? $default;
    }

    /** @return Generator<int, array<string, mixed>> */
    public function getIterator(): Generator
    {
        for ($start = 0; $start < $this->size; $start += self::INDEX_BLOCK_ROWS) {
            $count = min(self::INDEX_BLOCK_ROWS, $this->size - $start);
            $entries = $this->index->read(($this->offset + $start) * self::INDEX_BYTES, $count * self::INDEX_BYTES);
            for ($position = 0; $position < $count;) {
                $first = unpack('Joffset/Jlength', $entries, $position * self::INDEX_BYTES);
                $length = $first['length'];
                $end = $position + 1;
                while ($end < $count) {
                    $next = unpack('Joffset/Jlength', $entries, $end * self::INDEX_BYTES);
                    $span = $next['offset'] + $next['length'] - $first['offset'];
                    if ($span > self::READ_BLOCK_BYTES) {
                        break;
                    }
                    // Read through small gaps so filtered rows still use bounded blocks.
                    $length = $span;
                    $end++;
                }
                $bytes = $this->records->read($first['offset'], $length);
                for (; $position < $end; $position++) {
                    $entry = unpack('Joffset/Jlength', $entries, $position * self::INDEX_BYTES);
                    yield $start + $position => $this->decode(substr($bytes, $entry['offset'] - $first['offset'], $entry['length']));
                }
                unset($bytes);
            }
        }
    }

    public function offsetExists(mixed $offset): bool
    {
        return is_int($offset) && $offset >= 0 && $offset < $this->size;
    }

    /** @return array<string, mixed> */
    public function offsetGet(mixed $offset): array
    {
        if (! is_int($offset)) {
            throw new OutOfBoundsException('Training row indexes must be integers.');
        }

        return $this->at($offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException('Training row views are immutable.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('Training row views are immutable.');
    }

    private function entryBytes(int $position): string
    {
        return $this->index->read(($this->offset + $position) * self::INDEX_BYTES, self::INDEX_BYTES);
    }

    /** @param array<string, mixed> $row */
    private static function encode(array $row): string
    {
        // Host serialization settings must not round training values on their way to disk.
        $precision = ini_get('serialize_precision');
        if ($precision === '-1') {
            return serialize($row);
        }
        if (ini_set('serialize_precision', '-1') === false) {
            throw new RuntimeException('Cannot preserve temporary training record precision.');
        }

        try {
            return serialize($row);
        } finally {
            ini_set('serialize_precision', $precision);
        }
    }

    /** @return array<string, mixed> */
    private function decode(string $bytes): array
    {
        $row = unserialize($bytes, ['allowed_classes' => false]);
        if (! is_array($row)) {
            throw new RuntimeException('Invalid temporary training record.');
        }

        return $this->projection === null ? $row : ($this->projection)($row);
    }
}
