<?php

namespace App\Domain\Operations;

use Illuminate\Queue\Connectors\RedisConnector;

final class ManagedRedisConnector extends RedisConnector
{
    public function connect(array $config)
    {
        return new ManagedRedisQueue($this->redis, $config['queue'],
            $config['connection'] ?? $this->connection, $config['retry_after'] ?? 60,
            $config['block_for'] ?? null, $config['after_commit'] ?? null,
            (int) ($config['migration_batch_size'] ?? 100));
    }
}
