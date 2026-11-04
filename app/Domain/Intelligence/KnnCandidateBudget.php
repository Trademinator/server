<?php

namespace App\Domain\Intelligence;

use Closure;
use InvalidArgumentException;
use RuntimeException;

/** Shared neighbor work is charged to every K; each K pays only for its own voting. */
final class KnnCandidateBudget
{
    private Closure $clock;

    private float $checkedAt;

    private float $sharedSeconds = 0.0;

    /** @var array<int, float> */
    private array $candidateSeconds = [];

    private float $longestCandidateSeconds = 0.0;

    public function __construct(
        private float $deadline,
        private ?float $secondsPerCandidate,
        private string $message,
        ?Closure $clock = null,
    ) {
        if ($secondsPerCandidate !== null && (! is_finite($secondsPerCandidate) || $secondsPerCandidate <= 0)) {
            throw new InvalidArgumentException('A KNN candidate time budget must be finite and positive.');
        }

        $this->clock = $clock ?? static fn (): float => microtime(true);
        $this->checkedAt = ($this->clock)();
        $this->assertWithinBudget($this->checkedAt);
    }

    public function checkpointShared(): void
    {
        TrainingRuntime::heartbeat();
        $this->checkpoint(null);
    }

    public function checkpointCandidate(int $k): void
    {
        $this->checkpoint($k);
    }

    private function checkpoint(?int $k): void
    {
        $now = ($this->clock)();
        $elapsed = max(0.0, $now - $this->checkedAt);
        $this->checkedAt = $now;
        if ($k === null) {
            $this->sharedSeconds += $elapsed;
        } else {
            $this->candidateSeconds[$k] = ($this->candidateSeconds[$k] ?? 0.0) + $elapsed;
            $this->longestCandidateSeconds = max($this->longestCandidateSeconds, $this->candidateSeconds[$k]);
        }

        $this->assertWithinBudget($now);
    }

    private function assertWithinBudget(float $now): void
    {
        if ($now > $this->deadline || ($this->secondsPerCandidate !== null
            && $this->sharedSeconds + $this->longestCandidateSeconds > $this->secondsPerCandidate)) {
            $limits = [];
            if ($now > $this->deadline) {
                $limits[] = 'outer_deadline';
            }
            if ($this->secondsPerCandidate !== null
                && $this->sharedSeconds + $this->longestCandidateSeconds > $this->secondsPerCandidate) {
                $limits[] = 'candidate_budget';
            }

            throw new RuntimeException(sprintf(
                '%s [limits=%s; shared_seconds=%.6F; max_candidate_voting_seconds=%.6F; candidate_limit_seconds=%s; outer_remaining_seconds=%.6F]',
                $this->message,
                implode(',', $limits),
                $this->sharedSeconds,
                $this->longestCandidateSeconds,
                $this->secondsPerCandidate === null ? 'none' : sprintf('%.6F', $this->secondsPerCandidate),
                $this->deadline - $now,
            ));
        }
    }
}
