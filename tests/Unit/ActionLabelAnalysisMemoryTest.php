<?php

use Symfony\Component\Process\Process;

it('labels a full year of one-minute history within the 512 MiB worker budget', function () {
    $process = new Process([
        PHP_BINARY, '-d', 'memory_limit=512M',
        dirname(__DIR__).'/Support/action-label-analysis-memory-check.php', '527041', 'alternating',
    ], dirname(__DIR__, 2));
    $process->setTimeout(120);

    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
    $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    expect($result)->toMatchArray(['rows' => 527041, 'memory_limit' => '512M',
        // Captured from the original algorithm with enough RAM to complete.
        'actions_sha256' => '28f8531b8c97a786bef685a1b4d78afcf840e3b595c14c0e3b8bf98d62a94a12']);
    expect($result['peak_bytes'])->toBeLessThan(400 * 1024 * 1024);
    expect($result['diagnostics'])->toMatchArray([
        'horizon' => 1, 'distance_observations' => 527037, 'pivot_count' => 527038,
        'action_counts' => ['buy' => 263519, 'hold' => 0, 'sell' => 263519],
    ]);
});
