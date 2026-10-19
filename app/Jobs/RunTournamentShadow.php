<?php

namespace App\Jobs;

use App\Domain\Intelligence\TournamentShadow;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

final class RunTournamentShadow implements ShouldQueue
{
    use Queueable;
    public int $timeout = 600;
    public int $tries = 2;

    public function __construct(public string $modelId, public string $mode)
    {
        $this->onQueue((string) config('intelligence.tournament.queue', 'tournament'));
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('shadow:'.$this->modelId.':'.$this->mode))
            ->dontRelease()->expireAfter(700)];
    }

    public function handle(TournamentShadow $shadow): void
    {
        if ($this->mode === 'predict') $shadow->predict($this->modelId);
        elseif ($this->mode === 'score') $shadow->score($this->modelId);
    }
}
