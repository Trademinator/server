<?php

namespace App\Domain\Operations;

use App\Domain\Intelligence\IntelligenceTrainer;
use App\Domain\Intelligence\KnnSchemaFallback;
use App\Domain\Intelligence\ModelStore;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\RedisJob;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

/** Explicit scalar metadata keeps queue inspection and cancellation free of deserialization. */
final class QueuePayload
{
    private ?array $current = null;

    private const JOBS = [
        'CollectMarketFeed', 'BuildMarketFeatures', 'BuildOwnerDatasetSnapshot',
        'TrainMarketIntelligence', 'TrainIntelligenceTournament', 'RunTournamentShadow',
        'AnalyzeMarketAutoLabels', 'BackfillMarketHistory', 'RebuildBackfilledIntelligence',
        'RecoverMarketHistory', 'RepairMissingCandles', 'SyncMarketCandles', 'RecordMarketSignal',
        'ExportPortableArchivePart', 'ImportPortableArchivePart', 'VerifyPortableArchivePart',
    ];

    private const STRINGS = [
        'exchange', 'symbol', 'period', 'marketId', 'historyId', 'gapId', 'requestId',
        'datasetId', 'modelId', 'snapshotDataset', 'schema', 'week', 'mode', 'source',
        'leaseToken', 'cacheKey', 'transferId', 'uniqueLockOwner', 'dispatchLockOwner',
    ];

    private const NUMBERS = [
        'from', 'to', 'fromMs', 'toMs', 'asOfMs', 'requestedAsOfMs', 'maxRows', 'sequence',
        'replayFromMs', 'replayCutoffMs', 'checkpointBeforeMs', 'chunkCandles', 'queueRequestedAt',
    ];

    /** @return array<string, mixed> */
    public function metadata(object $job, int $createdAt): array
    {
        $properties = [];
        foreach ([...self::STRINGS, ...self::NUMBERS] as $property) {
            if (isset($job->{$property}) && (is_string($job->{$property}) || is_int($job->{$property}))) {
                $properties[$property] = $job->{$property};
            }
        }
        $unique = null;
        if ($job instanceof ShouldBeUnique && ! method_exists($job, 'uniqueVia')) {
            $unique = ['key' => UniqueLock::getKey($job), 'owner' => $job->uniqueLockOwner ?? '',
                'store' => (string) config('cache.default')];
        }

        $requestedAt = (int) ($properties['queueRequestedAt'] ?? $createdAt);
        if ($this->current !== null) {
            $sameMarket = isset($properties['marketId']) && $properties['marketId'] === $this->current['market_id'];
            $sameRequest = false;
            foreach (['historyId', 'gapId', 'requestId', 'datasetId', 'modelId'] as $property) {
                $sameRequest = $sameRequest || (isset($properties[$property])
                    && $properties[$property] === ($this->current['_properties'][$property] ?? null));
            }
            $sameStream = isset($properties['exchange'], $properties['symbol'], $properties['period'])
                && [$properties['exchange'], $properties['symbol'], $properties['period']]
                    === [$this->current['exchange'], $this->current['symbol'], $this->current['period']];
            if ($sameMarket || $sameStream || $sameRequest) {
                $requestedAt = min($requestedAt, $this->current['requested_at'] ?? $requestedAt);
            }
        }

        $stage = match (class_basename($job)) {
            'BuildMarketFeatures' => 'features', 'BuildOwnerDatasetSnapshot' => 'snapshot',
            'AnalyzeMarketAutoLabels' => 'labels', 'TrainMarketIntelligence' => 'ai', default => null,
        };
        $requests = [];
        if ($stage !== null && isset($properties['exchange'], $properties['symbol'], $properties['period'])) {
            $requests = DB::table('knowledge_stage_requests')
                ->where('exchange', $properties['exchange'])->where('symbol', $properties['symbol'])
                ->where('period', $properties['period'])->where('stage', $stage)
                ->whereIn('status', ['queued', 'blocked'])->get(['request_id', 'required_through_ms'])
                ->map(fn ($request): array => (array) $request)->all();
        }

        return [
            'properties' => $properties,
            'requested_at' => $requestedAt,
            'unique' => $unique,
            'batched' => ! empty($job->batchId),
            'chain_remaining' => count($job->chained ?? []),
            'requests' => $requests,
        ];
    }

    public function processing(JobProcessing $event): void
    {
        $this->current = null;
        if (! $event->job instanceof RedisJob) {
            return;
        }
        $row = $this->read($event->job->getRawBody(), 'reserved');
        $this->current = app(QueuePriority::class)->enrich([$row], $event->job->getQueue())[0];
    }

    public function attempted(JobAttempted $event): void
    {
        $this->current = null;
    }

    /** @return array<string, mixed> */
    public function read(string $raw, string $state = 'waiting', ?int $availableAt = null): array
    {
        $payload = json_decode($raw, true);
        $payload = is_array($payload) ? $payload : [];
        $class = $payload['data']['commandName'] ?? '';
        $class = is_string($class) && preg_match('/\A[A-Za-z0-9_\\\\]{1,200}\z/D', $class) ? $class : '';
        $known = str_starts_with($class, 'App\\Jobs\\') && in_array(substr($class, 9), self::JOBS, true);
        $context = is_array($payload['queue_context'] ?? null) ? $payload['queue_context'] : [];
        $hasMetadata = is_array($context['properties'] ?? null);
        $command = $payload['data']['command'] ?? '';
        $legacy = ! $hasMetadata && $known && is_string($command) ? $this->serializedProperties($command, $class) : null;
        $properties = $hasMetadata ? $context['properties'] : ($legacy ?? []);
        $properties = array_filter($properties, fn ($value): bool => is_string($value) || is_int($value));
        $unique = $context['unique'] ?? ($known ? $this->legacyUnique($class, $properties) : null);
        $uniqueJob = $known && is_a($class, ShouldBeUnique::class, true);
        $batched = (bool) ($context['batched'] ?? false) || ! empty($properties['batchId']);
        $archivePart = in_array(class_basename($class), ['ExportPortableArchivePart', 'ImportPortableArchivePart', 'VerifyPortableArchivePart'], true);
        $canCancel = $known && ($hasMetadata || $legacy !== null) && ! $batched && ! $archivePart && $state !== 'reserved'
            && (! $uniqueJob || (is_array($unique) && ! empty($unique['owner']) && ! empty($unique['key'])));
        $reason = match (true) {
            $state === 'reserved' => 'A worker has already reserved this job.',
            ! $known => 'This job type has no registered cancellation cleanup.',
            $batched => 'Batch cancellation requires its batch coordinator.',
            $archivePart => 'Archive parts require coordinated transfer cancellation so running parts are preserved.',
            ! $hasMetadata && $legacy === null => 'Job metadata is incomplete; cancellation cleanup cannot be verified safely.',
            ! $canCancel => 'This legacy unique job has no verifiable lock owner; let it finish or expire.',
            default => null,
        };
        $created = (int) ($payload['createdAt'] ?? 0);
        $requested = (int) ($context['requested_at'] ?? $properties['queueRequestedAt'] ?? $created);
        $options = array_intersect_key($properties, array_flip([
            'from', 'to', 'fromMs', 'toMs', 'asOfMs', 'requestedAsOfMs', 'maxRows', 'sequence',
            'replayFromMs', 'replayCutoffMs', 'chunkCandles', 'datasetId', 'modelId',
            'snapshotDataset', 'schema', 'mode', 'source',
        ]));

        return [
            'uuid' => is_string($payload['uuid'] ?? null) && preg_match('/\A[0-9a-f-]{36}\z/iD', $payload['uuid'])
                ? $payload['uuid'] : hash('sha256', $raw),
            'name' => $class !== '' ? class_basename($class) : 'Unknown job',
            'class' => $class, 'state' => $state,
            'exchange' => $properties['exchange'] ?? null, 'symbol' => $properties['symbol'] ?? null,
            'period' => $properties['period'] ?? null, 'market_id' => $properties['marketId'] ?? null,
            'created_at' => $created ?: null, 'requested_at' => $requested ?: null,
            'available_at' => $availableAt, 'attempts' => (int) ($payload['attempts'] ?? 0),
            'timeout' => $payload['timeout'] ?? null, 'max_tries' => $payload['maxTries'] ?? null,
            'subscriber_count' => 0, 'manual' => null, 'priority_reason' => 'Waiting order',
            'options' => $options, 'can_cancel' => $canCancel, 'cancel_reason' => $reason,
            'can_move' => $state === 'waiting', 'chain_remaining' => (int) ($context['chain_remaining'] ?? 0),
            '_properties' => $properties, '_unique' => $unique, '_requests' => $context['requests'] ?? [],
        ];
    }

    /** @return array<string, mixed> */
    public function publicDetails(array $row): array
    {
        unset($row['_properties'], $row['_unique'], $row['_requests'], $row['_index'], $row['_raw']);

        return $row;
    }

    /** Read top-level scalar properties without creating PHP objects or following references. */
    private function serializedProperties(string $command, string $class): ?array
    {
        if (strlen($command) > 1048576
            || ! preg_match('/\AO:(\d{1,5}):"([A-Za-z0-9_\\\\]+)":(\d{1,5}):\{/', $command, $header)
            || $header[2] !== $class || strlen($class) !== (int) $header[1] || (int) $header[3] > 256) {
            return null;
        }
        $offset = strlen($header[0]);
        $budget = 10000;
        $properties = [];
        try {
            for ($index = 0; $index < (int) $header[3]; $index++) {
                $key = $this->serializedValue($command, $offset, $budget);
                $value = $this->serializedValue($command, $offset, $budget);
                if (is_string($key)) {
                    $key = substr($key, (int) strrpos("\0".$key, "\0"));
                    if (in_array($key, [...self::STRINGS, ...self::NUMBERS, 'batchId'], true)
                        && (is_string($value) || is_int($value))) {
                        $properties[$key] = $value;
                    }
                }
            }
            if (substr($command, $offset) !== '}') {
                return null;
            }
        } catch (UnexpectedValueException) {
            return null;
        }

        return $properties;
    }

    /** Skip bounded nested values and length-prefixed strings, including serialized chained jobs. */
    private function serializedValue(string $command, int &$offset, int &$budget, int $depth = 0): string|int|null
    {
        if (--$budget < 0 || $depth > 32) {
            throw new UnexpectedValueException('Serialized job metadata exceeds the inspection limit.');
        }
        if (substr($command, $offset, 2) === 'N;') {
            $offset += 2;

            return null;
        }
        if (preg_match('/\G([sE]):(\d{1,7}):"/', $command, $match, 0, $offset)) {
            $offset += strlen($match[0]);
            $length = (int) $match[2];
            $value = $length <= 512 && $match[1] === 's' ? substr($command, $offset, $length) : null;
            $offset += $length;
            if (substr($command, $offset, 2) !== '";') {
                throw new UnexpectedValueException('Invalid serialized string.');
            }
            $offset += 2;

            return $value;
        }
        if (preg_match('/\G([bidRr]):([^;]{1,64});/', $command, $match, 0, $offset)) {
            $offset += strlen($match[0]);

            return $match[1] === 'i' && preg_match('/\A-?\d{1,18}\z/D', $match[2]) ? (int) $match[2] : null;
        }
        $count = null;
        if (preg_match('/\Ga:(\d{1,5}):\{/', $command, $match, 0, $offset)) {
            $count = (int) $match[1];
        } elseif (preg_match('/\GO:\d{1,5}:"[A-Za-z0-9_\\\\]+":(\d{1,5}):\{/', $command, $match, 0, $offset)) {
            $count = (int) $match[1];
        }
        if ($count !== null && $count * 2 <= $budget) {
            $offset += strlen($match[0]);
            for ($index = 0; $index < $count * 2; $index++) {
                $this->serializedValue($command, $offset, $budget, $depth + 1);
            }
            if (substr($command, $offset, 1) !== '}') {
                throw new UnexpectedValueException('Invalid serialized collection.');
            }
            $offset++;

            return null;
        }

        throw new UnexpectedValueException('Unsupported serialized metadata.');
    }

    /** @return array{key:string,owner:string,store:string}|null */
    private function legacyUnique(string $class, array $properties): ?array
    {
        $owner = $properties['uniqueLockOwner'] ?? '';
        if (! is_string($owner) || $owner === '') {
            return null;
        }
        $exchange = $properties['exchange'] ?? '';
        $symbol = $properties['symbol'] ?? '';
        $period = $properties['period'] ?? '';
        $marketKey = ModelStore::marketKey($exchange, $symbol, $period);
        $id = match (class_basename($class)) {
            'BuildMarketFeatures', 'BuildOwnerDatasetSnapshot' => $marketKey,
            'RecordMarketSignal' => $properties['marketId'] ?? null,
            'RecoverMarketHistory' => $properties['requestId'] ?? null,
            'TrainIntelligenceTournament' => isset($properties['datasetId'], $properties['maxRows'])
                ? $properties['datasetId'].':'.$properties['maxRows'] : null,
            'ExportPortableArchivePart', 'ImportPortableArchivePart', 'VerifyPortableArchivePart' => isset($properties['transferId'], $properties['sequence']) ? $properties['transferId'].'|'.$properties['sequence'] : null,
            'TrainMarketIntelligence' => isset($properties['week'], $properties['schema'])
                ? $marketKey.':'.$properties['week'].':'.$properties['schema'].':'.IntelligenceTrainer::VERSION.':'.KnnSchemaFallback::VERSION : null,
            default => null,
        };
        if ($id === null) {
            return null;
        }
        $name = class_basename($class) === 'TrainMarketIntelligence'
            ? hash('xxh128', $class.':'.$exchange.':'.$symbol.':'.$period) : $class;

        return ['key' => 'laravel_unique_job:'.$name.':'.$id, 'owner' => $owner,
            'store' => (string) config('cache.default')];
    }
}
