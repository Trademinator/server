<?php

namespace App\Domain\MarketData;

use ccxt\Exchange;
use Composer\InstalledVersions;
use RuntimeException;
use Symfony\Component\Process\Process;

/** Refreshes facts automatically; only matching source reviews confer trust. */
class ExchangeMetadataBuilder
{
    public const SCHEMA = 2;

    public function build(string $project): array
    {
        $reviewPath = $project.'/resources/data/ccxt-access-reviews.json';
        $reviews = self::read($reviewPath);
        $metadata = [
            'schema' => self::SCHEMA,
            'ccxt_version' => InstalledVersions::getPrettyVersion('ccxt/ccxt'),
            'ccxt_reference' => InstalledVersions::getReference('ccxt/ccxt'),
            'reviews_sha256' => is_file($reviewPath) ? hash_file('sha256', $reviewPath) : null,
            'exchanges' => [],
        ];
        foreach (Exchange::$exchanges as $id) {
            $command = [PHP_BINARY];
            if (php_ini_loaded_file()) {
                array_push($command, '-c', php_ini_loaded_file());
            }
            array_push($command, '-d', 'memory_limit=512M', $project.'/scripts/build-exchange-metadata.php', $id);
            $process = new Process($command);
            $process->setTimeout(30);
            try {
                $process->mustRun();
                $entry = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($entry) || ! isset($entry['source_fingerprint'], $entry['source_files'])) {
                    throw new RuntimeException('Invalid adapter inspection.');
                }
                $entry['access'] = self::classify($entry, $reviews['exchanges'][$id] ?? null);
            } catch (\Throwable) {
                // Never retain a previous public classification after inspection fails.
                $entry = ['name' => $id, 'spot' => false, 'fetchOHLCV' => false, 'timeframes' => [],
                    'access' => self::unknown('inspection_failed')];
            }
            $metadata['exchanges'][$id] = $entry;
        }
        ksort($metadata['exchanges']);

        return $metadata;
    }

    public static function classify(array $entry, ?array $review): array
    {
        if ($review === null) {
            return self::unknown('not_reviewed');
        }
        if (($review['source_fingerprint'] ?? null) !== ($entry['source_fingerprint'] ?? null)
            || ! isset($entry['source_fingerprint'])) {
            return self::unknown('source_changed');
        }
        $states = ['public', 'authentication_required', 'unknown'];
        $markets = $review['markets'] ?? null;
        $candles = $review['candles'] ?? null;
        if (! in_array($markets, $states, true) || ! in_array($candles, $states, true)
            || empty($review['evidence']) || empty($review['note'])) {
            return self::unknown('invalid_review');
        }
        // Unsupported unified methods do not establish a public OHLCV service.
        $state = match (true) {
            $entry['fetchOHLCV'] !== true => 'unknown',
            $markets === 'unknown' || $candles === 'unknown' => 'unknown',
            $markets === 'authentication_required' || $candles === 'authentication_required' => 'authentication_required',
            default => 'public',
        };

        return ['state' => $state, 'markets' => $markets, 'candles' => $candles,
            'reason' => $entry['fetchOHLCV'] !== true ? 'ohlcv_not_supported' : 'source_reviewed'];
    }

    public static function unknown(string $reason): array
    {
        return ['state' => 'unknown', 'markets' => 'unknown', 'candles' => 'unknown', 'reason' => $reason];
    }

    public static function read(string $path): array
    {
        $data = is_file($path) ? json_decode(file_get_contents($path), true) : null;

        return is_array($data) ? $data : [];
    }

    public static function write(string $path, array $data): void
    {
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException('Could not create metadata directory.');
        }
        $temporary = tempnam($directory, '.ccxt-');
        if ($temporary === false) {
            throw new RuntimeException('Could not create metadata file.');
        }
        try {
            $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
            if (file_put_contents($temporary, $json) !== strlen($json) || ! chmod($temporary, 0644) || ! rename($temporary, $path)) {
                throw new RuntimeException('Could not replace exchange metadata.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    public static function report(array $metadata, array $previous): array
    {
        $entries = $metadata['exchanges'];
        $old = $previous['exchanges'] ?? [];
        $counts = array_fill_keys(['public', 'authentication_required', 'unknown'], 0);
        $changed = $pending = [];
        foreach ($entries as $id => $entry) {
            $counts[$entry['access']['state']]++;
            if (isset($old[$id]) && ($old[$id]['source_fingerprint'] ?? null) !== ($entry['source_fingerprint'] ?? null)) {
                $changed[] = $id;
            }
            if ($entry['access']['state'] === 'unknown' && $entry['access']['reason'] !== 'ohlcv_not_supported') {
                $pending[] = $id;
            }
        }

        return ['ccxt_version' => $metadata['ccxt_version'], 'counts' => $counts,
            'added' => array_values(array_diff(array_keys($entries), array_keys($old))),
            'removed' => array_values(array_diff(array_keys($old), array_keys($entries))),
            'changed' => $changed, 'needs_review' => $pending, 'exchanges' => $entries];
    }
}
