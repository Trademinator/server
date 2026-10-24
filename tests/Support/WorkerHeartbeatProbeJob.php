<?php

namespace Tests\Support;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

final class WorkerHeartbeatProbeJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 15;

    public int $tries = 1;

    public function __construct(public string $probeKey) {}

    public function handle(): void
    {
        $redis = app('redis')->connection('default');
        $redis->set($this->probeKey.':memory', ini_get('memory_limit'));
        $redis->set($this->probeKey.':entered', '1');
        // Deliberately block inside the extension without any application checkpoint.
        if (! is_array($redis->blpop([$this->probeKey.':release'], 10))) {
            throw new RuntimeException('The test did not release the worker probe in time.');
        }
        $redis->set($this->probeKey.':finished', '1');
    }
}
