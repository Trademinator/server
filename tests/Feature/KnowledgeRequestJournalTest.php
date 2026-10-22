<?php

use App\Domain\Intelligence\KnowledgeRequestJournal;
use Illuminate\Support\Facades\DB;

it('coalesces requests and clears only satisfied work for one exchange market period', function () {
    $journal = app(KnowledgeRequestJournal::class);
    $first = $journal->request('kraken', 'BTC/USD', '1m', 'features', 100);
    expect($journal->request('kraken', 'BTC/USD', '1m', 'features', 200))->toBe($first);
    $otherPeriod = $journal->request('kraken', 'BTC/USD', '5m', 'features', 100);
    expect($otherPeriod)->not->toBe($first);

    $journal->markSatisfied('kraken', 'BTC/USD', '1m', 'features', 150);
    expect($journal->outstanding('kraken', 'BTC/USD', '1m', 'features'))->toBe(1);
    $journal->markSatisfied('kraken', 'BTC/USD', '1m', 'features', 200);
    expect($journal->outstanding('kraken', 'BTC/USD', '1m', 'features'))->toBe(0)
        ->and($journal->outstanding('kraken', 'BTC/USD', '5m', 'features'))->toBe(1);
    expect(DB::table('knowledge_stage_requests')->where('request_id', $first)->value('completed_through_ms'))
        ->toBe(200);
});

it('retains errors and allows a new request after an unsuccessful build', function () {
    $journal = app(KnowledgeRequestJournal::class);
    $first = $journal->request('bitso', 'ATOM/USD', '1h', 'labels', 100);
    $journal->fail('bitso', 'ATOM/USD', '1h', 'labels', 'failed labeling');
    expect($journal->outstanding('bitso', 'ATOM/USD', '1h', 'labels'))->toBe(0)
        ->and(DB::table('knowledge_stage_requests')->where('request_id', $first)->value('error'))
        ->toBe('failed labeling');
    $second = $journal->request('bitso', 'ATOM/USD', '1h', 'labels', 200);
    expect($second)->not->toBe($first);
});
