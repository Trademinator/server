<?php

use App\Domain\Intelligence\ModelStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function latestReportFixture(string $key, string $id, string $createdAt, array $report): void
{
    $dataset = (string) Str::uuid7();
    DB::table('research_datasets')->insert([
        'dataset_id' => $dataset, 'manifest' => '{}', 'created_at' => $createdAt,
    ]);
    DB::table('intelligence_models')->insert([
        'model_id' => $id, 'dataset_id' => $dataset, 'market_key' => $key,
        'status' => $report['status'], 'sha256' => str_repeat('0', 64),
        'report' => json_encode($report, JSON_THROW_ON_ERROR), 'created_at' => $createdAt,
    ]);
}

it('reads the latest failed attempt without replacing the published report or loading artifacts', function () {
    $key = ModelStore::marketKey('bitso', 'BTC/MXN', '4h');
    $published = (string) Str::uuid7();
    $candidate = (string) Str::uuid7();
    latestReportFixture($key, $published, '2026-10-21 20:00:00', [
        'model_id' => $published, 'status' => 'ready',
    ]);
    latestReportFixture($key, $candidate, '2026-11-26 00:12:40', [
        'status' => 'abstaining', 'reason' => 'outcome_knn_unavailable',
        'outcome' => ['status' => 'abstaining', 'reason' => 'no_eligible_k'],
        'action' => ['status' => 'ready', 'reason' => 'validated'],
    ]);
    DB::table('intelligence_heads')->insert([
        'market_key' => $key, 'model_id' => $published, 'updated_at' => '2026-10-21 20:00:00',
    ]);
    config(['intelligence.path' => '/missing-artifacts-must-not-be-read']);

    $store = app(ModelStore::class);
    $latest = $store->latestReport('bitso', 'BTC/MXN', '4h');

    expect($latest)->toMatchArray([
        'model_id' => $candidate, 'created_at' => '2026-11-26 00:12:40',
        'status' => 'abstaining', 'reason' => 'outcome_knn_unavailable',
        'action' => ['status' => 'ready', 'reason' => 'validated'],
    ]);
    expect($store->currentReport('bitso', 'BTC/MXN', '4h')['model_id'])->toBe($published);
    $this->assertDatabaseHas('intelligence_heads', ['market_key' => $key, 'model_id' => $published]);
});

it('selects one deterministic latest attempt per requested market and period in one query', function () {
    $fourHourKey = ModelStore::marketKey('bitso', 'BTC/MXN', '4h');
    $hourKey = ModelStore::marketKey('bitso', 'BTC/MXN', '1h');
    $otherKey = ModelStore::marketKey('kraken', 'BTC/MXN', '4h');
    $older = '01900000-0000-7000-8000-000000000099';
    $tieFirst = '01900000-0000-7000-8000-000000000001';
    $tieLast = '01900000-0000-7000-8000-000000000002';
    $hourModel = '01900000-0000-7000-8000-000000000003';
    latestReportFixture($fourHourKey, $older, '2026-11-24 00:00:00', ['status' => 'ready']);
    latestReportFixture($fourHourKey, $tieFirst, '2026-11-25 00:00:00', ['status' => 'ready']);
    latestReportFixture($fourHourKey, $tieLast, '2026-11-25 00:00:00', ['status' => 'abstaining']);
    latestReportFixture($hourKey, $hourModel, '2026-11-25 01:00:00', ['status' => 'ready']);
    latestReportFixture($otherKey, (string) Str::uuid7(), '2026-11-26 00:00:00', ['status' => 'ready']);
    DB::enableQueryLog();
    DB::flushQueryLog();

    try {
        $reports = app(ModelStore::class)->latestReports([$fourHourKey, $hourKey, $fourHourKey]);
        $queries = DB::getQueryLog();
    } finally {
        DB::disableQueryLog();
    }

    expect($reports->map(fn (array $report): string => $report['model_id'])->all())->toEqual([
        $fourHourKey => $tieLast, $hourKey => $hourModel,
    ]);
    expect($queries)->toHaveCount(1);
});

it('returns no latest report for an unbuilt market and skips queries for an empty market set', function () {
    $store = app(ModelStore::class);

    expect($store->latestReport('bitso', 'BTC/MXN', '4h'))->toBeNull();
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $reports = $store->latestReports([]);
        $queries = DB::getQueryLog();
    } finally {
        DB::disableQueryLog();
    }

    expect($reports)->toBeEmpty();
    expect($queries)->toBeEmpty();
});
