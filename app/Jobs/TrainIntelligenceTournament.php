<?php

namespace App\Jobs;

use App\Domain\Intelligence\ResearchTournament;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** A single research dataset is processed by one bounded worker at a time. */
final class TrainIntelligenceTournament implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 900;
    public int $uniqueFor = 7200;
    public array $backoff = [120, 300];

    public function __construct(public string $datasetId, public int $maxRows = 1200)
    {
        $this->onQueue((string) config('intelligence.tournament.queue', 'tournament'));
    }

    public function uniqueId(): string
    {
        return $this->datasetId.':'.$this->maxRows;
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('trademinator:tournament:'.$this->datasetId))
            ->releaseAfter(120)->expireAfter(1800)];
    }

    public function handle(ResearchTournament $tournament): void
    {
        $key = 'trademinator:tournament:status:'.$this->datasetId;
        Cache::put($key, ['status' => 'running', 'started_at' => now()->toIso8601String()], now()->addDays(2));
        try {
            $result = $tournament->run($this->datasetId, $this->maxRows);
            Cache::put($key, ['status' => 'completed', 'finished_at' => now()->toIso8601String(),
                'tasks' => array_keys($result['tasks'])], now()->addDays(2));
        } catch (Throwable $error) {
            Cache::put($key, ['status' => 'retrying', 'error' => $error->getMessage()], now()->addDays(2));
            throw $error;
        }
    }

    public function failed(?Throwable $error): void
    {
        Cache::put('trademinator:tournament:status:'.$this->datasetId,
            ['status' => 'failed', 'error' => $error?->getMessage() ?? 'Queue attempts exhausted'],
            now()->addDays(2));
    }
}
