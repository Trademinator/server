<?php

use App\Domain\Intelligence\CoinGeckoInsight;
use Rubix\ML\Classifiers\RandomForest;
use Tests\Support\IntelligenceClock;
use Tests\TestCase;

uses(TestCase::class);

it('trains a bounded independent Rubix forest on distinct past context and reports probabilities', function (): void {
    config([
        'intelligence.coingecko_insight.enabled' => true,
        'intelligence.coingecko_insight.min_snapshots' => 80,
        'intelligence.coingecko_insight.max_snapshots' => 500,
        'intelligence.coingecko_insight.min_holdout' => 20,
        'intelligence.coingecko_insight.trees' => 12,
        'intelligence.coingecko_insight.depth' => 5,
        'intelligence.coingecko_insight.min_f1_improvement' => 0.0,
    ]);
    $classes = ['super_bear', 'bear', 'neutral', 'bull', 'super_bull'];
    $rows = [];
    $base = 1700000000000;
    for ($i = 0; $i < 250; $i++) {
        $class = $i % count($classes);
        $point = 0.1 + $class * 0.2 + ($i % 3) * 0.002;
        $features = [
            'context.global_regime' => $point,
            'context.btc_dominance' => $point,
            'context.activity' => $point,
        ];
        $rows[] = [
            'decision_at_ms' => $base + $i * 3600000,
            'label_available_at_ms' => $base + ($i + 1) * 3600000,
            'label' => $classes[$class],
            'context_snapshot_id' => "snapshot-{$i}",
            'context_features' => $features,
        ];
        // Several source candles must never multiply the training weight of
        // one provider observation.
        $rows[] = [...$rows[array_key_last($rows)],
            'decision_at_ms' => $base + $i * 3600000 + 60000];
    }

    $advisor = new CoinGeckoInsight;
    $result = $advisor->train($rows, 'enhanced', microtime(true) + 30);
    expect($result['bundle']['snapshots'])->toBe(250)
        ->and($result['bundle']['input_keys'])->toBe([
            'context.global_regime', 'context.btc_dominance', 'context.activity',
        ])
        ->and($result['bundle']['status'])->toBe('validated')
        ->and($result['estimator'])->toBeInstanceOf(RandomForest::class);
    $prediction = $advisor->predict($result['bundle'], [
        'context_snapshot_id' => 'latest',
        'features' => ['context.global_regime' => 0.7, 'context.btc_dominance' => 0.7,
            'context.activity' => 0.7],
    ], $result['estimator']);
    expect($prediction['reason'])->toBe('supported')
        ->and(array_sum($prediction['votes']))->toBeGreaterThan(0.999999)
        ->and($prediction['outcome'])->toBeIn($classes);
});

it('keeps baseline Core independent from optional CoinGecko', function (): void {
    config(['intelligence.coingecko_insight.enabled' => true]);

    $result = (new CoinGeckoInsight)->train([], 'core', microtime(true) + 30);
    expect($result['bundle']['status'])->toBe('profile_without_context')
        ->and($result)->not->toHaveKey('estimator');
});

it('stops optional context preparation when its own run allowance is exhausted', function (): void {
    config(['intelligence.coingecko_insight.enabled' => true]);
    IntelligenceClock::freeze(1000.0);
    $visited = 0;
    $rows = (function () use (&$visited): Generator {
        for ($i = 0; $i < 10; $i++) {
            $visited++;
            IntelligenceClock::advance(1.0);
            yield ['context_snapshot_id' => 'snapshot-'.$i, 'label' => 'bull'];
        }
    })();

    try {
        $result = (new CoinGeckoInsight)->train($rows, 'enhanced', 1002.0);

        expect($result['bundle']['status'])->toBe('optional_budget_exhausted')
            ->and($result['bundle']['influence'])->toBeFalse()
            ->and($result)->not->toHaveKey('estimator')
            ->and($visited)->toBe(2);
    } finally {
        IntelligenceClock::reset();
    }
});
