<?php

namespace App\Domain\Intelligence;

use App\Domain\Research\DatasetStore;
use ArrayAccess;
use Countable;
use IteratorAggregate;
use LogicException;
use OutOfBoundsException;
use RuntimeException;
use Traversable;

/**
 * A complete contiguous Action context, with a bounded cache of mutable rows.
 * Prices and timestamps stay exact on disk; only action/reservation flags mutate.
 * Chunk boundaries never become artificial candle gaps or label endpoints.
 *
 * @implements ArrayAccess<int, array<string, mixed>>
 * @implements IteratorAggregate<int, array<string, mixed>>
 */
final class ActionCandleBuffer implements ArrayAccess, Countable, IteratorAggregate
{
    private const INDEX_BYTES = 13;

    private const CACHE_ROWS = 512;

    private mixed $data;

    private mixed $index;

    private int $rows = 0;

    private array $cache = [];

    private array $dirtyPages = [];

    private array $dataPages = [];

    private array $savedFlags = [];

    private bool $replaceCheckpoint = false;

    public function __construct(private ?BuildCheckpoint $checkpoint = null, bool $restore = true)
    {
        $this->data = tmpfile();
        $this->index = tmpfile();
        if ($this->data === false || $this->index === false) {
            throw new RuntimeException('Cannot create temporary Action label storage.');
        }
        // Unlink owned scratch files immediately on Linux so SIGKILL cannot leave them behind.
        if (PHP_OS_FAMILY !== 'Windows') {
            foreach ([$this->data, $this->index] as $file) {
                if (! unlink(stream_get_meta_data($file)['uri'])) {
                    throw new RuntimeException('Cannot isolate temporary Action label storage.');
                }
            }
        }
        $this->restoreCheckpoint($restore);
    }

    public function restoreCheckpoint(bool $restore): void
    {
        if ($this->checkpoint === null) {
            return;
        }
        if ($restore) {
            $flags = $this->checkpoint->chunks('action-flags');
            $flags->rewind();
            foreach ($this->checkpoint->chunks('action-candles') as $page => $bytes) {
                $rows = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
                $encoded = $flags->valid() ? $flags->current() : '';
                $bits = base64_decode($encoded, true);
                if ($bits === false || strlen($bits) !== count($rows)) {
                    throw new RuntimeException('Action checkpoint flags are missing or corrupt.');
                }
                $this->savedFlags[$page] = hash('sha256', $encoded);
                foreach ($rows as $index => $row) {
                    $flag = ord($bits[$index]);
                    $action = [null, 'buy', 'sell', 'hold'][$flag & 3];
                    if ($action !== null) {
                        $row['action'] = $action;
                    }
                    if ($flag & 4) {
                        $row['_auto_hold_doji'] = true;
                    }
                    if ($flag & 8) {
                        $row['_auto_hold_middle'] = true;
                    }
                    $this[] = $row;
                }
                $flags->next();
            }
            if ($flags->valid() || $this->rows !== ($this->checkpoint->state['action_rows'] ?? 0)) {
                throw new RuntimeException('Action checkpoint row count mismatch.');
            }
            $this->dirtyPages = $this->dataPages = [];
        } else {
            $this->replaceCheckpoint = true;
        }
        $this->checkpoint->onSave('action-candles', function (): void {
            if ($this->replaceCheckpoint) {
                $this->checkpoint->clearStream('action-candles');
                $this->checkpoint->clearStream('action-flags');
                $this->replaceCheckpoint = false;
            }
            // Flush current references without evicting them. Candle values are immutable;
            // subsequent passes persist only the changed one-byte flags, not full OHLCV again.
            foreach ($this->cache as $index => $row) {
                $this->seek($this->index, $index * self::INDEX_BYTES + 12);
                DatasetStore::write($this->index, chr($this->flags($row)));
            }
            foreach (array_keys($this->dirtyPages) as $page) {
                $count = min(self::CACHE_ROWS, $this->rows - $page * self::CACHE_ROWS);
                $this->seek($this->index, $page * self::CACHE_ROWS * self::INDEX_BYTES);
                $indexBytes = $this->read($this->index, $count * self::INDEX_BYTES);
                $flags = '';
                $rows = [];
                for ($i = 0; $i < $count; $i++) {
                    $record = unpack('Poffset/Vlength/Cflags', substr($indexBytes, $i * self::INDEX_BYTES, self::INDEX_BYTES));
                    $flags .= chr($record['flags']);
                    if (isset($this->dataPages[$page])) {
                        $this->seek($this->data, $record['offset']);
                        $rows[] = json_decode($this->read($this->data, $record['length']), true, flags: JSON_THROW_ON_ERROR);
                    }
                }
                $encoded = base64_encode($flags);
                $hash = hash('sha256', $encoded);
                if (($this->savedFlags[$page] ?? null) !== $hash) {
                    $this->checkpoint->put('action-flags', $page, $encoded);
                    $this->savedFlags[$page] = $hash;
                }
                if (isset($this->dataPages[$page])) {
                    $this->checkpoint->put('action-candles', $page, BuildCheckpoint::encode($rows));
                }
            }
            $this->dirtyPages = $this->dataPages = [];
            $this->checkpoint->state['action_rows'] = $this->rows;
        });
    }

    public function count(): int
    {
        return $this->rows;
    }

    public function offsetExists(mixed $offset): bool
    {
        return is_int($offset) && $offset >= 0 && $offset < $this->rows;
    }

    public function &offsetGet(mixed $offset): mixed
    {
        if (! $this->offsetExists($offset)) {
            throw new OutOfBoundsException('Unknown Action candle offset.');
        }
        if ($this->checkpoint !== null) {
            $this->dirtyPages[intdiv($offset, self::CACHE_ROWS)] = true;
        }
        if (! isset($this->cache[$offset])) {
            if (count($this->cache) >= self::CACHE_ROWS) {
                $oldest = array_key_first($this->cache);
                $this->seek($this->index, $oldest * self::INDEX_BYTES + 12);
                DatasetStore::write($this->index, chr($this->flags($this->cache[$oldest])));
                unset($this->cache[$oldest]);
            }
            $this->seek($this->index, $offset * self::INDEX_BYTES);
            $record = unpack('Poffset/Vlength/Cflags', $this->read($this->index, self::INDEX_BYTES));
            $this->seek($this->data, $record['offset']);
            $row = json_decode($this->read($this->data, $record['length']), true, flags: JSON_THROW_ON_ERROR);
            $action = [null, 'buy', 'sell', 'hold'][$record['flags'] & 3];
            if ($action !== null) {
                $row['action'] = $action;
            }
            if ($record['flags'] & 4) {
                $row['_auto_hold_doji'] = true;
            }
            if ($record['flags'] & 8) {
                $row['_auto_hold_middle'] = true;
            }
            $this->cache[$offset] = $row;
        }

        return $this->cache[$offset];
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset !== null || ! is_array($value)) {
            throw new LogicException('Action candles may only be appended; mutate label flags through their row.');
        }
        $flags = $this->flags($value);
        unset($value['action'], $value['_auto_hold_doji'], $value['_auto_hold_middle']);
        $bytes = json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        if (fseek($this->data, 0, SEEK_END) !== 0 || fseek($this->index, 0, SEEK_END) !== 0) {
            throw new RuntimeException('Cannot append temporary Action candle storage.');
        }
        $position = ftell($this->data);
        if ($position === false) {
            throw new RuntimeException('Cannot locate temporary Action candle storage.');
        }
        DatasetStore::write($this->data, $bytes);
        DatasetStore::write($this->index, pack('PVC', $position, strlen($bytes), $flags));
        if ($this->checkpoint !== null) {
            $this->dirtyPages[intdiv($this->rows, self::CACHE_ROWS)] = true;
            $this->dataPages[intdiv($this->rows, self::CACHE_ROWS)] = true;
        }
        $this->rows++;
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('Removing candles would change the Action context.');
    }

    public function &getIterator(): Traversable
    {
        foreach ($this->iterateFrom(0) as $index => &$row) {
            yield $index => $row;
        }
    }

    public function &iterateFrom(int $start): Traversable
    {
        for ($index = $start; $index < $this->rows; $index++) {
            $row = &$this->offsetGet($index);
            yield $index => $row;
        }
    }

    private function flags(array $row): int
    {
        return match ($row['action'] ?? null) {
            null => 0, 'buy' => 1, 'sell' => 2, 'hold' => 3,
            default => throw new LogicException('Unexpected temporary Action label.'),
        } | (($row['_auto_hold_doji'] ?? false) ? 4 : 0)
            | (($row['_auto_hold_middle'] ?? false) ? 8 : 0);
    }

    private function seek(mixed $file, int $position): void
    {
        if (fseek($file, $position) !== 0) {
            throw new RuntimeException('Cannot seek temporary Action label storage.');
        }
    }

    private function read(mixed $file, int $length): string
    {
        $bytes = '';
        while (strlen($bytes) < $length) {
            $part = fread($file, $length - strlen($bytes));
            if ($part === false || $part === '') {
                throw new RuntimeException('Truncated temporary Action label storage.');
            }
            $bytes .= $part;
        }

        return $bytes;
    }

    public function __destruct()
    {
        foreach ([$this->data, $this->index] as $file) {
            if (is_resource($file)) {
                fclose($file);
            }
        }
    }
}
