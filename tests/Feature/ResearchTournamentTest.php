<?php

use App\Domain\Intelligence\ResearchTournament;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Support\IntelligenceFixtures;

beforeEach(function () {
    $root = sys_get_temp_dir().'/rubix-tournament-'.Str::uuid7();
    config(['research.path' => $root.'/research', 'intelligence.path' => $root.'/models']);
});

afterEach(function () {
    File::deleteDirectory(dirname(config('research.path')));
});

it('evaluates the four Rubix candidates for Outcome and Action without changing published models', function () {
    $manifest = IntelligenceFixtures::snapshot(125);
    $report = app(ResearchTournament::class)->run($manifest['dataset_id'], 120);
    expect($report['status'])->toBe('research_only')
        ->and($report['tasks'])->toHaveKeys(['action', 'outcome'])
        ->and($report['tasks']['action']['candidates'])->toHaveKeys(ResearchTournament::CANDIDATES)
        ->and($report['tasks']['outcome']['candidates'])->toHaveKeys(ResearchTournament::CANDIDATES);
    foreach (['action', 'outcome'] as $task) {
        foreach (ResearchTournament::CANDIDATES as $candidate) {
            expect($report['tasks'][$task]['candidates'][$candidate]['status'])->toBe('evaluated', $task.' '.$candidate.' '.json_encode($report['tasks'][$task]['candidates'][$candidate]));
        }
    }
    foreach (['action', 'outcome'] as $task) {
        expect($report['tasks'][$task]['baseline'])->toHaveKeys(['majority_class', 'macro_f1', 'directional_precision']);
        foreach (ResearchTournament::CANDIDATES as $name) {
            $candidate = $report['tasks'][$task]['candidates'][$name];
            expect($candidate)->toHaveKeys(['folds', 'failed_gates', 'eligible', 'research_rank_score', 'calibration'])
                ->and($candidate['calibration'])->toHaveCount(5)
                ->and($candidate['folds'])->toHaveCount(3);
        }
    }
    $this->assertDatabaseCount('intelligence_models', 0);
    $this->assertDatabaseCount('intelligence_heads', 0);
    $this->assertDatabaseCount('intelligence_tournaments', 1);
});

it('isolates candidate training into bounded PHP subprocesses without changing published models', function () {
    $manifest = IntelligenceFixtures::snapshot(120);
    $report = app(ResearchTournament::class)->run($manifest['dataset_id'], 110, isolate: true);
    expect($report['tasks']['action']['candidates']['decision_tree']['status'])->toBe('evaluated', json_encode($report['tasks']['action']['candidates']['decision_tree']));
    $this->assertDatabaseCount('intelligence_heads', 0);
});
