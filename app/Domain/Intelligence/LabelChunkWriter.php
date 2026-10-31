<?php

namespace App\Domain\Intelligence;

use App\Domain\Research\DatasetStore;
use Generator;
use LogicException;
use RuntimeException;

/** Spool bounded, immutable JSON chunks before atomically publishing a label set. */
final class LabelChunkWriter
{
    public const ROWS_PER_CHUNK = 256;

    public const KINDS = ['action_labels', 'action_available_at_ms', 'outcome_labels'];

    private mixed $file;

    private array $buffers = [];

    private array $counts = [];

    private array $chunks = [];

    private array $hashes = [];

    private array $last = [];

    private bool $finished = false;

    private array $pendingRecords = [];

    private int $savedRecords = 0;

    public function __construct(private ?BuildCheckpoint $checkpoint = null)
    {
        $this->file = tmpfile();
        if ($this->file === false) {
            throw new RuntimeException('Cannot create temporary label chunk storage.');
        }
        if (PHP_OS_FAMILY !== 'Windows' && ! unlink(stream_get_meta_data($this->file)['uri'])) {
            throw new RuntimeException('Cannot isolate temporary label chunk storage.');
        }
        foreach (self::KINDS as $kind) {
            $this->buffers[$kind] = [];
            $this->counts[$kind] = $this->chunks[$kind] = 0;
            $this->hashes[$kind] = hash_init('sha256');
            $this->last[$kind] = -1;
        }
        if ($checkpoint !== null) {
            foreach ($checkpoint->chunks('labels') as $bytes) {
                $record = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
                $kind = $record['kind'];
                if (! isset($this->counts[$kind]) || $record['chunk'] !== $this->chunks[$kind]
                    || ! hash_equals($record['sha256'], hash('sha256', $record['payload']))) {
                    throw new RuntimeException('Invalid saved label chunk.');
                }
                DatasetStore::write($this->file, $bytes."\n");
                $this->counts[$kind] += $record['row_count'];
                $this->chunks[$kind]++;
                $this->last[$kind] = $record['last_ms'];
                hash_update($this->hashes[$kind], self::fingerprint($record));
                $this->savedRecords++;
            }
            if ($this->savedRecords !== ($checkpoint->state['label_records'] ?? 0)) {
                throw new RuntimeException('Label checkpoint chunk count mismatch.');
            }
            $checkpoint->onSave('labels', function (): void {
                foreach (self::KINDS as $kind) {
                    $this->flush($kind);
                }
                foreach ($this->pendingRecords as $record) {
                    $this->checkpoint->put('labels', $this->savedRecords++, BuildCheckpoint::encode($record));
                }
                $this->pendingRecords = [];
                $this->checkpoint->state['label_records'] = $this->savedRecords;
            });
        }
    }

    public function append(string $kind, int $timestamp, mixed $value): void
    {
        if ($this->finished || ! isset($this->buffers[$kind]) || $timestamp <= $this->last[$kind]) {
            throw new LogicException('Label chunks must be written once in strictly increasing timestamp order.');
        }
        $this->last[$kind] = $timestamp;
        $this->buffers[$kind][$timestamp] = $value;
        $this->counts[$kind]++;
        if (count($this->buffers[$kind]) >= self::ROWS_PER_CHUNK) {
            $this->flush($kind);
        }
    }

    public function manifest(): array
    {
        if (! $this->finished) {
            foreach (self::KINDS as $kind) {
                $this->flush($kind);
            }
            $this->finished = true;
        }
        $manifest = [];
        foreach (self::KINDS as $kind) {
            $manifest[$kind] = ['rows' => $this->counts[$kind], 'chunks' => $this->chunks[$kind],
                'sha256' => hash_final(hash_copy($this->hashes[$kind]))];
        }

        return $manifest;
    }

    public function records(): Generator
    {
        $this->manifest();
        if (! rewind($this->file)) {
            throw new RuntimeException('Cannot rewind temporary label chunks.');
        }
        while (($line = fgets($this->file)) !== false) {
            yield json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        }
        if (! feof($this->file)) {
            throw new RuntimeException('Cannot read temporary label chunks.');
        }
    }

    /** Bind chunk positions, ranges, counts and content checksums to the set header. */
    public static function fingerprint(array $chunk): string
    {
        return implode('|', [$chunk['chunk'], $chunk['first_ms'], $chunk['last_ms'],
            $chunk['row_count'], $chunk['sha256']])."\n";
    }

    private function flush(string $kind): void
    {
        $rows = $this->buffers[$kind];
        if ($rows === []) {
            return;
        }
        $payload = json_encode($rows, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        $record = ['kind' => $kind, 'chunk' => $this->chunks[$kind]++,
            'first_ms' => array_key_first($rows), 'last_ms' => array_key_last($rows),
            'row_count' => count($rows), 'sha256' => hash('sha256', $payload), 'payload' => $payload];
        hash_update($this->hashes[$kind], self::fingerprint($record));
        DatasetStore::write($this->file, json_encode($record, JSON_THROW_ON_ERROR)."\n");
        if ($this->checkpoint !== null) {
            $this->pendingRecords[] = $record;
        }
        $this->buffers[$kind] = [];
    }

    public function __destruct()
    {
        if (is_resource($this->file)) {
            fclose($this->file);
        }
    }
}
