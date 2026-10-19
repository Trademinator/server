<?php

use App\Domain\Intelligence\TournamentShadow;
use App\Domain\Intelligence\ResearchTournament;
use App\Jobs\RunTournamentShadow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Support\IntelligenceFixtures;

beforeEach(function () {
    $root = sys_get_temp_dir().'/trademinator-shadow-test-'.Str::uuid7();
    config(['research.path'=>$root.'/research', 'intelligence.path'=>$root.'/models']);
});

afterEach(function () {
    File::deleteDirectory(dirname(config('research.path')));
});

it('never publishes a challenger from missing, stale, or ineligible research', function () {
    $manifest=IntelligenceFixtures::snapshot(120);
    expect(fn () => app(TournamentShadow::class)->publish($manifest['dataset_id']))
        ->toThrow(RuntimeException::class);
    $tournament=app(ResearchTournament::class)->run($manifest['dataset_id'],110);
    DB::table('intelligence_tournaments')->where('dataset_id',$manifest['dataset_id'])
        ->update(['report'=>json_encode([...$tournament,'version'=>'obsolete'])]);
    expect(fn () => app(TournamentShadow::class)->publish($manifest['dataset_id']))
        ->toThrow(RuntimeException::class);
    $this->assertDatabaseCount('tournament_shadow_models',0);
    $this->assertDatabaseCount('intelligence_heads',0);
});

it('dispatches prediction and scoring only to the research queue', function () {
    Queue::fake();
    config(['queue.default'=>'database']);
    $this->artisan('trademinator:shadow',['mode'=>'predict'])->assertSuccessful();
    $this->artisan('trademinator:shadow',['mode'=>'score'])->assertSuccessful();
    Queue::assertNotPushed(RunTournamentShadow::class);
    $this->assertDatabaseCount('market_signals',0);
});

it('does not allow an invalid shadow action', function () {
    $this->artisan('trademinator:shadow',['mode'=>'trade'])->assertFailed();
});
