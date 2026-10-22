<?php

use App\Domain\Intelligence\LeadLagIntelligence;
use App\Domain\Intelligence\LeadLagTrainer;
use App\Domain\Intelligence\PatternCatalog;
use App\Domain\Intelligence\PatternTrainer;
use App\Domain\Intelligence\ProbabilityCalibration;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\IntelligenceClock;
use Tests\Support\IntelligenceFixtures;
use Tests\Support\LeadLagFixtures;

afterEach(function () {
    IntelligenceClock::reset();
});

function auxiliaryPatternRows(): array
{
    $rows = [];
    for ($index = 0; $index < 180; $index++) {
        $complete = $index % 2 === 0;
        $rows[] = [
            'vector' => [$complete ? 0.0 : 1.0],
            'decision_at_ms' => $index * 1000,
            'patterns' => array_map(fn (string $type): array => [
                'type' => $type, 'length' => 3, 'stage' => 2,
                'progress' => 2 / 3, 'similarity' => 0.8,
                'label' => $complete ? 'completed' : 'failed',
                'label_available_at_ms' => ($index + 2) * 1000,
            ], PatternCatalog::TYPES),
        ];
    }

    return $rows;
}

it('gives every pattern and learner its own allowance while preserving complete validation reports', function () {
    config(['intelligence.max_seconds' => 1.0]);
    $rows = auxiliaryPatternRows();
    $settings = ['min_samples' => 50, 'min_block_rows' => 10, 'trees' => 1, 'k' => 5];
    $trainer = new PatternTrainer(new PatternCatalog, new ProbabilityCalibration);
    IntelligenceClock::freeze(100.0);
    mt_srand(42);
    $expected = $trainer->train($rows, $settings, 200.0);
    $clock = 100.0;
    IntelligenceClock::useClock(function () use (&$clock): float {
        return $clock += 0.001;
    });
    mt_srand(42);

    $result = $trainer->train($rows, $settings, 200.0);

    expect($clock)->toBeGreaterThan(101.0);
    expect($result['report'])->toBe($expected['report']);
    expect($result['models'])->toHaveCount(4);
    foreach ($result['report'] as $report) {
        expect($report['candidates'])->toHaveKeys(['random_forest', 'weighted_knn']);
    }
});

it('stops pattern work when either the individual allowance or caller cap expires', function (float $budget, float $outerDeadline) {
    config(['intelligence.max_seconds' => $budget]);
    $rows = auxiliaryPatternRows();
    $clock = 100.0;
    IntelligenceClock::useClock(function () use (&$clock): float {
        return $clock += 0.001;
    });
    $trainer = new PatternTrainer(new PatternCatalog, new ProbabilityCalibration);

    expect(fn () => $trainer->train(
        $rows, ['min_samples' => 50, 'min_block_rows' => 10, 'trees' => 1, 'k' => 5], $outerDeadline
    ))->toThrow(RuntimeException::class, 'Pattern training time budget exceeded.');
})->with([
    'one run exceeds its own allowance' => [0.25, 200.0],
    'caller hard cap is shorter' => [1.0, 100.05],
]);

it('gives each lead lag parameter candidate and final holdout an independent allowance', function () {
    config(['intelligence.max_seconds' => 10.0]);
    [$leader, $follower] = LeadLagFixtures::series(8000);
    $context = ['timezone' => 'UTC', 'timezone_source' => 'operator', 'region_prior' => null];
    $settings = config('lead_lag');
    $trainer = new LeadLagTrainer;
    IntelligenceClock::freeze(100.0);
    $expected = $trainer->train($leader, $follower, 60000, $context, $settings, 1000.0);
    $clock = 100.0;
    IntelligenceClock::useClock(function () use (&$clock): float {
        return $clock += 0.001;
    });

    $result = $trainer->train($leader, $follower, 60000, $context, $settings, 1000.0);

    expect($clock)->toBeGreaterThan(110.0);
    expect($result)->toBe($expected);
    expect($result['report']['candidates'])->toHaveCount(30);
    expect($result['model']['lag'])->toBe(3);
});

it('stops lead lag work when either the individual allowance or caller cap expires', function (float $budget, float $outerDeadline) {
    config(['intelligence.max_seconds' => $budget]);
    [$leader, $follower] = LeadLagFixtures::series(1600);
    $context = ['timezone' => 'UTC', 'timezone_source' => 'unknown', 'region_prior' => null];
    $clock = 100.0;
    IntelligenceClock::useClock(function () use (&$clock): float {
        return $clock += 0.001;
    });

    expect(fn () => (new LeadLagTrainer)->train(
        $leader, $follower, 60000, $context, config('lead_lag'), $outerDeadline
    ))->toThrow(RuntimeException::class, 'Lead/lag training time budget exceeded.');
})->with([
    'one run exceeds its own allowance' => [0.25, 200.0],
    'caller hard cap is shorter' => [10.0, 100.05],
]);

it('prepares later peers and post-training evidence after earlier peer runs use their own time', function () {
    $this->travelTo('2024-01-02 00:00:00 UTC');
    config(['intelligence.max_seconds' => 10.0]);
    LeadLagFixtures::market('kraken');
    LeadLagFixtures::market('bitso');
    LeadLagFixtures::market('ndax');
    LeadLagFixtures::candles();
    DB::table('tickers')->select(['symbol', 'period', 'microtimestamp', 'payload', 'created_at', 'updated_at'])
        ->where('exchange', 'bitso')->orderBy('microtimestamp')->chunk(100, function ($bars): void {
            $copies = [];
            foreach ($bars as $bar) {
                $copies[] = [...(array) $bar, 'ticker_id' => (string) Str::uuid7(), 'exchange' => 'ndax'];
            }
            DB::table('tickers')->insert($copies);
        });
    $manifest = ['exchange' => 'kraken', 'symbol' => 'BTC/USD', 'period' => '1m',
        'as_of_ms' => IntelligenceFixtures::START + 903 * 60000];
    $rows = array_map(fn (int $index): array => ['decision_at_ms' => IntelligenceFixtures::START + $index * 60000], range(1, 900));
    $clock = 100.0;
    IntelligenceClock::useClock(function () use (&$clock): float {
        return $clock += 0.001;
    });

    $result = app(LeadLagIntelligence::class)->prepare($manifest, $rows, 200.0);

    expect($clock)->toBeGreaterThan(110.0);
    expect($result['bundle']['models'])->toHaveKeys(['bitso', 'ndax']);
    expect($result['bundle']['report']['bitso']['selected'])->toBe(3);
    expect($result['bundle']['report']['ndax']['selected'])->toBe(3);
    expect($result['series']['bitso'])->not->toBeEmpty();
    expect($result['series']['ndax'])->toBe($result['series']['bitso']);
});

it('skips peer training when the configured peer cap is zero or negative', function (int $maximumPeers) {
    config(['intelligence.max_seconds' => 10.0, 'lead_lag.max_peers' => $maximumPeers]);
    LeadLagFixtures::market('kraken');
    LeadLagFixtures::market('bitso');
    LeadLagFixtures::market('ndax');
    $manifest = ['exchange' => 'kraken', 'symbol' => 'BTC/USD', 'period' => '1m',
        'as_of_ms' => IntelligenceFixtures::START + 10 * 60000];
    $rows = array_map(fn (int $index): array => ['decision_at_ms' => IntelligenceFixtures::START + $index * 60000], range(1, 3));
    IntelligenceClock::freeze(100.0);

    $result = app(LeadLagIntelligence::class)->prepare($manifest, $rows, 200.0);

    expect($result['bundle']['report'])->toBe([])
        ->and($result['bundle']['models'])->toBe([])
        ->and($result['bundle']['peers_omitted_by_cap'])->toBe(2)
        ->and($result['series'])->toBe([]);
})->with([0, -2]);

it('rejects lead lag preparation that returns after its own allowance', function () {
    $this->travelTo('2024-01-02 00:00:00 UTC');
    config(['intelligence.max_seconds' => 10.0]);
    $manifest = ['exchange' => 'kraken', 'symbol' => 'BTC/USD', 'period' => '1m',
        'as_of_ms' => IntelligenceFixtures::START + 903 * 60000];
    $rows = array_map(fn (int $index): array => ['decision_at_ms' => IntelligenceFixtures::START + $index * 60000], range(1, 900));
    IntelligenceClock::freeze(100.0);
    DB::listen(function (QueryExecuted $query): void {
        if (str_contains($query->sql, 'tickers')) {
            IntelligenceClock::advance(20.0);
        }
    });

    expect(fn () => app(LeadLagIntelligence::class)->prepare($manifest, $rows, 200.0))
        ->toThrow(RuntimeException::class, 'Lead/lag preparation time budget exceeded.');
});
