<?php

use App\Domain\Intelligence\TrainingRows;

it('preserves every record and numeric value when spooling a one-pass source', function () {
    $vectors = [0.1 + 0.2, PHP_FLOAT_MIN, PHP_FLOAT_MAX, 0.0, -0.0];
    $source = [
        ['decision_at_ms' => 1720000000001, 'label_available_at_ms' => 1720000060001,
            'vector' => $vectors, 'feature_weights' => [1.0, 0.5, 0.0, 1.0, 0.25],
            'label' => 'bull', 'action_label' => null],
        ['decision_at_ms' => 1720000120001, 'label_available_at_ms' => 1720000180001,
            'vector' => $vectors, 'feature_weights' => [1.0, 0.5, 0.0, 1.0, 0.25],
            'label' => 'bull', 'action_label' => 'buy'],
    ];
    $input = (static function () use ($source): Generator {
        yield 10 => $source[0];
        yield 30 => $source[1];
    })();

    $rows = TrainingRows::from($input);

    expect(iterator_to_array($rows))->toBe($source);
    expect(pack('d*', ...$rows[0]['vector']))->toBe(pack('d*', ...$vectors));
    expect(count($rows))->toBe(2);
    expect($rows->max('label_available_at_ms'))->toBe(1720000180001);
});

it('keeps selected rows readable after their original owner is released and during nested iteration', function () {
    $streamsBefore = count(get_resources('stream'));
    $source = TrainingRows::from(array_map(static fn (int $id): array => ['id' => $id], range(0, 6)));
    $reference = WeakReference::create($source);
    $selected = $source->filter(static fn (array $row): bool => $row['id'] % 2 === 0)
        ->slice(1, 2)->map(static fn (array $row): array => $row + ['label' => 'buy']);
    unset($source);
    $pairs = [];

    foreach ($selected as $left) {
        foreach ($selected as $right) {
            $pairs[] = [$left['id'], $right['id']];
        }
    }

    expect(iterator_to_array($selected))->toBe([['id' => 2, 'label' => 'buy'], ['id' => 4, 'label' => 'buy']]);
    expect($pairs)->toBe([[2, 2], [2, 4], [4, 2], [4, 4]]);
    expect($reference->get())->toBeNull();
    unset($selected);
    expect(count(get_resources('stream')))->toBe($streamsBefore);
});

it('preserves exact floats under host serialization settings without changing the input generator settings', function () {
    $original = ini_set('serialize_precision', '3');
    $row = ['vector' => [0.12345678901234567, 0.9999999999999999, -0.0], 'label' => 'buy'];
    $observed = [];
    $input = (static function () use ($row, &$observed): Generator {
        $observed[] = ini_get('serialize_precision');
        yield $row;
        $observed[] = ini_get('serialize_precision');
        yield $row;
    })();

    try {
        $rows = TrainingRows::from($input);

        expect(pack('d*', ...$rows[0]['vector']))->toBe(pack('d*', ...$row['vector']));
        expect($rows[1])->toBe($row);
        expect($observed)->toBe(['3', '3']);
        expect(ini_get('serialize_precision'))->toBe('3');
        expect(fn () => TrainingRows::from([['invalid' => static fn (): int => 1]]))->toThrow(Exception::class);
        expect(ini_get('serialize_precision'))->toBe('3');
    } finally {
        ini_set('serialize_precision', $original);
    }
});

it('keeps iterator blocks independent from nested iteration and random reads on shared files', function () {
    $rows = TrainingRows::from(array_map(static fn (int $id): array => ['id' => $id], range(0, 699)));
    $selected = $rows->slice(125, 400);
    $nested = $rows->filter(static fn (array $row): bool => $row['id'] % 2 === 0)->slice(80, 150);
    $actual = [];

    foreach ($selected as $position => $row) {
        expect($rows[699 - $position]['id'])->toBe(699 - $position);
        if ($position % 100 === 0) {
            expect(array_column(iterator_to_array($nested), 'id'))->toBe(range(160, 458, 2));
        }
        $actual[] = $row['id'];
    }

    expect($actual)->toBe(range(125, 524));
});

it('selects the same positional ranges as array slices', function (int $offset, ?int $length, array $expected) {
    $rows = TrainingRows::from(array_map(static fn (int $id): array => ['id' => $id], range(0, 4)));

    $selected = $rows->slice($offset, $length);

    expect(array_column(iterator_to_array($selected), 'id'))->toBe($expected);
    expect(count($selected))->toBe(count($expected));
})->with([
    'full tail' => [2, null, [2, 3, 4]],
    'last two' => [-2, null, [3, 4]],
    'negative length' => [1, -1, [1, 2, 3]],
    'offset before beginning' => [-99, 2, [0, 1]],
    'offset after end' => [99, null, []],
    'zero length' => [0, 0, []],
    'negative empty length' => [3, -4, []],
]);

it('uses projected label availability for filtering and maximums without changing the source', function () {
    $source = TrainingRows::from([
        ['id' => 0, 'label' => 'bull', 'label_available_at_ms' => 90, 'action_label_available_at_ms' => 12],
        ['id' => 1, 'label' => 'bear', 'label_available_at_ms' => 91, 'action_label_available_at_ms' => 40],
    ]);
    $calls = 0;
    $action = $source->map(static function (array $row) use (&$calls): array {
        $calls++;
        $row['label'] = 'buy';
        $row['label_available_at_ms'] = $row['action_label_available_at_ms'];

        return $row;
    });
    expect($calls)->toBe(0);

    $mature = $action->filter(static fn (array $row): bool => $row['label_available_at_ms'] < 20);

    expect(count($mature))->toBe(1);
    expect($mature->max('label_available_at_ms'))->toBe(12);
    expect($mature[0]['label'])->toBe('buy');
    expect($source[0]['label_available_at_ms'])->toBe(90);
    expect($source[0]['label'])->toBe('bull');
});

it('rejects mutation and out-of-range access while supporting empty views', function () {
    $rows = TrainingRows::from([['id' => 0]]);
    $empty = $rows->filter(static fn (array $row): bool => false);

    expect($empty[0] ?? null)->toBeNull();
    expect($empty->max('id', -1))->toBe(-1);
    expect(iterator_to_array($empty))->toBe([]);
    expect(fn () => $rows->at(-1))->toThrow(OutOfBoundsException::class);
    expect(fn () => $rows->at(1))->toThrow(OutOfBoundsException::class);
    expect(fn () => $rows->offsetSet(0, ['id' => 1]))->toThrow(LogicException::class);
    expect(fn () => $rows->offsetUnset(0))->toThrow(LogicException::class);
});

it('releases temporary storage when the input generator fails', function () {
    $streamsBefore = count(get_resources('stream'));
    $input = (static function (): Generator {
        yield ['id' => 0];
        throw new RuntimeException('source interrupted');
    })();

    expect(fn () => TrainingRows::from($input))->toThrow(RuntimeException::class, 'source interrupted');

    expect(count(get_resources('stream')))->toBe($streamsBefore);
});

it('keeps heap usage bounded when retaining a large source and filtered views', function () {
    $input = (static function (): Generator {
        for ($id = 0; $id < 30000; $id++) {
            yield ['id' => $id, 'vector' => array_fill(0, 64, $id / 30000),
                'label' => 'buy', 'decision_at_ms' => $id * 60000,
                'label_available_at_ms' => ($id + 1) * 60000];
        }
    })();
    $before = memory_get_usage(true);
    memory_reset_peak_usage();

    $rows = TrainingRows::from($input);
    $selected = $rows->filter(static fn (array $row): bool => $row['id'] % 3 === 0)->slice(-5000);

    expect(count($rows))->toBe(30000);
    expect(count($selected))->toBe(5000);
    expect($selected[0]['id'])->toBe(15000);
    expect($selected[4999]['id'])->toBe(29997);
    expect(memory_get_peak_usage(true) - $before)->toBeLessThan(8 * 1024 * 1024);
});
