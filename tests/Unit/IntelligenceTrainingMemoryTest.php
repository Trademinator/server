<?php

use Symfony\Component\Process\Process;

it('projects and publishes all rich knowledge rows within a bounded PHP heap', function () {
    $process = new Process([
        PHP_BINARY, '-d', 'memory_limit=128M',
        dirname(__DIR__).'/Support/intelligence-training-memory-check.php', '60000',
    ], dirname(__DIR__, 2));
    $process->setTimeout(120);

    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
    $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    expect($result)->toMatchArray([
        'memory_limit' => '128M', 'source_rows' => 60000, 'knowledge_rows' => 60000,
        'published_rows' => 60000, 'source_unchanged' => true,
        // Search has its own full-history memory test. This isolates row preparation,
        // projections and publication without quadratic walk-forward tuning cost.
        'outcome_folds' => 0, 'action_folds' => 0,
    ]);
    expect($result['knowledge_sha256'])->toBe($result['expected_sha256'])
        ->and($result['peak_bytes'])->toBeLessThan(128 * 1024 * 1024);
});
