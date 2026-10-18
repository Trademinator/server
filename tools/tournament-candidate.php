<?php

// Research-only subprocess entrypoint. Receives and returns JSON on standard streams.
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

try {
    $input = stream_get_contents(STDIN, 8 * 1024 * 1024);
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
