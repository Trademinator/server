<?php

use App\Domain\Intelligence\TournamentCandidateRanking;

it('places the confirmed champion first then orders eligible and ineligible challengers', function () {
    $candidates = [
        'low' => ['research_rank_score' => 0.99, 'eligible' => false],
        'second' => ['research_rank_score' => 0.70, 'eligible' => true],
        'champion' => ['research_rank_score' => 0.80, 'eligible' => true],
        'third' => ['research_rank_score' => 0.65, 'eligible' => true],
    ];
    $ranked = TournamentCandidateRanking::rank(['research_leader'=>'champion', 'candidates'=>$candidates]);
    expect(array_keys($ranked))->toBe(['champion','second','third','low']);
    expect(array_keys(TournamentCandidateRanking::rank(['candidates'=>$candidates]))[0])->toBe('champion')
        ->and($candidates['low']['research_rank_score'])->toBe(0.99);
});
