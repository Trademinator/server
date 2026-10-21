<?php

namespace App\Domain\Intelligence;

use RuntimeException;

/** Shared ownership keeps temporary training files alive for every row view. */
final class TrainingRowFile
{
    /** @param resource $handle */
    private function __construct(private mixed $handle) {}

    public function __destruct()
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
    }

    public static function create(): self
    {
        $handle = tmpfile();
        if ($handle === false) {
            throw new RuntimeException('Cannot create temporary training storage.');
        }

        return new self($handle);
    }

    public function append(string $bytes): void
    {
        if (fseek($this->handle, 0, SEEK_END) !== 0 || fwrite($this->handle, $bytes) !== strlen($bytes)) {
            throw new RuntimeException('Cannot write temporary training storage.');
        }
    }

    public function read(int $offset, int $length): string
    {
        if (fseek($this->handle, $offset) !== 0
            || ($bytes = stream_get_contents($this->handle, $length)) === false || strlen($bytes) !== $length) {
            throw new RuntimeException('Cannot read temporary training storage.');
        }

        return $bytes;
    }
}
