<?php

namespace App\Console\Commands;

use App\Domain\Operations\WorkerRegistry;
use Illuminate\Console\Command;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

final class ManagedQueueWork extends Command
{
    protected $signature = 'trademinator:queue-work
        {connection? : The Redis queue connection}
        {--queue= : Comma-separated queue names}
        {--slot=1 : Worker slot on this host and queue pool}
        {--stop-when-empty : Stop when the ready queue is empty}
        {--max-time=50 : Stop between jobs after this many seconds}
        {--max-jobs=0 : Stop after this many jobs, or zero for no job-count limit}
        {--timeout=600 : Fallback job timeout; a job-specific timeout takes precedence}
        {--memory=384 : Worker recycle threshold in MiB}
        {--tries=3 : Fallback maximum attempts}
        {--sleep=3 : Idle poll interval in seconds}';

    protected $description = 'Drain Redis work from cron with an owned worker slot and independent heartbeat';

    public function handle(WorkerRegistry $registry): int
    {
        $lease = null;
        $process = null;
        $exitCode = self::FAILURE;
        $reason = null;
        $stopping = false;
        $handlers = [];
        try {
            $connection = (string) ($this->argument('connection') ?: config('queue.default'));
            if (config('queue.connections.'.$connection.'.driver') !== 'redis') {
                throw new RuntimeException('Managed queue workers require a Redis queue connection.');
            }
            if (! extension_loaded('pcntl')) {
                throw new RuntimeException('Managed workers require the PCNTL extension.');
            }
            $queues = array_values(array_filter(array_map('trim', explode(',', (string) ($this->option('queue')
                ?: config('queue.connections.'.$connection.'.queue', 'default'))))));
            if ($queues === [] || count($queues) > 32) {
                throw new RuntimeException('Choose between one and 32 queues for this worker.');
            }
            foreach (['max-time', 'max-jobs', 'timeout', 'memory', 'tries', 'sleep'] as $option) {
                if (filter_var($this->option($option), FILTER_VALIDATE_INT) === false || (int) $this->option($option) < 0) {
                    throw new RuntimeException('Worker option --'.$option.' must be a non-negative integer.');
                }
            }
            if ((int) $this->option('max-time') < 1 || (int) $this->option('timeout') < 1) {
                throw new RuntimeException('Finite workers require positive --max-time and --timeout values.');
            }
            $lease = $registry->acquire($connection, $queues, (string) $this->option('slot'));
            if ($lease === null) {
                $this->line('The worker slot is already occupied or queue maintenance is active.');

                return self::SUCCESS;
            }
            $arguments = [PHP_BINARY];
            if (php_ini_loaded_file() !== false) {
                array_push($arguments, '-c', php_ini_loaded_file());
            }
            array_push($arguments, '-d', 'memory_limit='.ini_get('memory_limit'), base_path('artisan'),
                'trademinator:queue-child', $connection, '--queue='.implode(',', $queues), '--no-interaction');
            foreach (['max-time', 'max-jobs', 'timeout', 'memory', 'tries', 'sleep'] as $option) {
                $arguments[] = '--'.$option.'='.$this->option($option);
            }
            if ($this->option('stop-when-empty')) {
                $arguments[] = '--stop-when-empty';
            }
            $process = new Process($arguments, base_path(), [
                'TRADEMINATOR_WORKER_ID' => $lease['worker_id'],
                'TRADEMINATOR_WORKER_SLOT_KEY' => $lease['slot_key'],
                'TRADEMINATOR_WORKER_OWNER' => $lease['owner'],
                'TRADEMINATOR_WORKER_SUPERVISOR' => (string) getmypid(),
            ], timeout: null);
            $asyncSignals = pcntl_async_signals(true);
            foreach ([SIGTERM, SIGINT] as $signal) {
                $handlers[$signal] = pcntl_signal_get_handler($signal);
                pcntl_signal($signal, function () use (&$stopping): void {
                    $stopping = true;
                });
            }
            $process->start(function (string $type, string $buffer): void {
                $this->output->write($buffer);
            });
            $childPid = $process->getPid();
            if ($childPid === null) {
                throw new RuntimeException('The worker process did not start.');
            }
            $lastHeartbeat = 0.0;
            $ownershipLost = false;
            $signalled = false;
            while ($process->isRunning()) {
                if ($stopping && ! $signalled) {
                    $process->signal(SIGTERM);
                    $signalled = true;
                }
                $now = hrtime(true) / 1_000_000_000;
                if (! $ownershipLost && $now - $lastHeartbeat >= $registry->heartbeatSeconds()) {
                    try {
                        if (! $registry->heartbeat($lease, $childPid, $stopping)) {
                            throw new RuntimeException('The worker slot lease expired or changed owner.');
                        }
                        $lastHeartbeat = $now;
                    } catch (Throwable $error) {
                        $reason = 'Worker coordination was lost; stop after the current job.';
                        $this->error($reason);
                        report($error);
                        $ownershipLost = $stopping = true;
                    }
                }
                $process->clearOutput();
                $process->clearErrorOutput();
                usleep(100_000);
            }
            $exitCode = $process->getExitCode() ?? self::FAILURE;
            if ($ownershipLost) {
                $exitCode = self::FAILURE;
            }
        } catch (Throwable $error) {
            $reason = $error instanceof RuntimeException ? $error->getMessage() : 'The worker could not complete safely; inspect the application log.';
            $this->error($reason);
            report($error);
            if ($process?->isRunning()) {
                $process->signal(SIGTERM);
                $process->wait();
            }
        } finally {
            foreach ($handlers as $signal => $handler) {
                pcntl_signal($signal, $handler);
            }
            if (isset($asyncSignals)) {
                pcntl_async_signals($asyncSignals);
            }
            if ($lease !== null && ! $process?->isRunning()) {
                try {
                    $registry->finish($lease, $exitCode, $reason);
                } catch (Throwable $error) {
                    report($error);
                }
            }
        }

        return $exitCode;
    }
}
