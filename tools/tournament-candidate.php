<?php

// Research-only subprocess entrypoint. Reads verified ephemeral file; outputs JSON.
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

try {
    if ($argc !== 3 || ! is_file($argv[1]) || ! preg_match('/^[a-f0-9]{64}$/', $argv[2])) {
        throw new InvalidArgumentException('Expected private input file and SHA-256 digest.');
    }
    $input = file_get_contents($argv[1]);
    if ($input === false || ! hash_equals($argv[2], hash('sha256', $input))) {
        throw new RuntimeException('Candidate input integrity check failed.');
    }
    $args = json_decode($input, true, flags: JSON_THROW_ON_ERROR);
    if (! is_array($args) || count($args['train'] ?? []) > 4000 || count($args['test'] ?? []) > 4000) {
        throw new InvalidArgumentException('Invalid candidate dataset.');
    }
    $result = app(\App\Domain\Intelligence\ResearchTournament::class)->evaluateCandidatePublic(
        $args['train'], $args['test'], $args['task'], $args['classes'], $args['name']);
    echo json_encode($result, JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error).': '.$error->getMessage());
    exit(1);
}
