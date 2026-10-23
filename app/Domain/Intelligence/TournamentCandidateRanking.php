<?php

namespace App\Domain\Intelligence;

/**
 * Presentation-only ranking. Research selection and production model heads
 * remain unchanged. Ineligible rank #1 is not a champion.
 */
final class TournamentCandidateRanking
{
    public static function rank(array $competition): array
    {
        $candidates = $competition['candidates'] ?? [];
        $champion = $competition['research_leader'] ?? null;
        uksort($candidates, static function (string $left, string $right) use ($candidates, $champion): int {
            if ($left === $champion) return -1;
            if ($right === $champion) return 1;
            $a = $candidates[$left];
            $b = $candidates[$right];
            foreach (['eligible', 'validation_eligible', 'holdout_confirmed'] as $gate) {
                $cmp = ((int)($b[$gate] ?? false)) <=> ((int)($a[$gate] ?? false));
                if ($cmp !== 0) return $cmp;
            }
            foreach (['research_rank_score', 'walk_forward_min', 'walk_forward_coverage'] as $metric) {
                $cmp = (float)($b[$metric] ?? -1) <=> (float)($a[$metric] ?? -1);
                if ($cmp !== 0) return $cmp;
            }

            return strcmp($left, $right);
        });

        return $candidates;
    }
}
