<?php

namespace App\Console\Commands;

use App\Domain\Operations\WorkerRegistry;
use Illuminate\Console\Command;

final class ManagedQueueChild extends Command
{
    protected $signature = 'trademinator:queue-child {connection}
        {--queue=} {--stop-when-empty} {--max-time=50} {--max-jobs=0}
        {--timeout=600} {--memory=384} {--tries=3} {--sleep=3}';

    protected $description = 'Internal child process for a supervised queue worker';

    protected $hidden = true;

    public function handle(WorkerRegistry $registry): int
    {
        // These ephemeral values are an IPC handoff, not application configuration.
        $registry->attach((string) getenv('TRADEMINATOR_WORKER_ID'),
            (string) getenv('TRADEMINATOR_WORKER_SLOT_KEY'), (string) getenv('TRADEMINATOR_WORKER_OWNER'),
            (int) getenv('TRADEMINATOR_WORKER_SUPERVISOR'));
        $arguments = ['connection' => $this->argument('connection'), '--queue' => $this->option('queue'),
            '--stop-when-empty' => (bool) $this->option('stop-when-empty')];
        foreach (['max-time', 'max-jobs', 'timeout', 'memory', 'tries', 'sleep'] as $option) {
            $arguments['--'.$option] = $this->option($option);
        }

        return $this->call('queue:work', $arguments);
    }
}
