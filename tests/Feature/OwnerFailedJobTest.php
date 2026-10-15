<?php

use App\Jobs\BuildMarketFeatures;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['queue.failed.driver' => 'database-uuids', 'queue.failed.database' => 'sqlite']);
    $this->withSession(['auth.password_confirmed_at' => time()]);
});

function failedJobOwner(): User
{
    $owner = User::factory()->create();
    config(['operations.owner_uuid' => $owner->user_id]);

    return $owner;
}

/** @param array<string, mixed> $attributes */
function recordedFailedJob(array $attributes = []): string
{
    $queuedId = Queue::connection('database')->push(new BuildMarketFeatures('kraken', 'BTC/USD', '15m'), '', 'features');
    $payload = json_decode(DB::table('jobs')->where('id', $queuedId)->value('payload'), true, flags: JSON_THROW_ON_ERROR);
    DB::table('jobs')->where('id', $queuedId)->delete();
    $payload['attempts'] = 4;
    $record = [
        'uuid' => $payload['uuid'],
        'connection' => 'database',
        'queue' => 'features',
        'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        'exception' => "RuntimeException: Feature replay exceeded its timeout\nStack trace:\n#0 /app/Jobs/BuildMarketFeatures.php(50)",
        'failed_at' => now(),
        ...$attributes,
    ];
    DB::connection(config('queue.failed.database'))->table(config('queue.failed.table'))->insert($record);

    return $record['uuid'];
}

dataset('failed job routes', [
    'view' => ['GET', 'owner.failed-jobs.show'],
    'requeue' => ['POST', 'owner.failed-jobs.retry'],
    'discard' => ['DELETE', 'owner.failed-jobs.destroy'],
]);

dataset('failed job mutations', [
    'requeue' => ['POST', 'owner.failed-jobs.retry', 1],
    'discard' => ['DELETE', 'owner.failed-jobs.destroy', 0],
]);

it('requires sign-in before viewing or changing a failed job', function (string $method, string $route) {
    $job = recordedFailedJob();

    $this->call($method, route($route, $job))->assertRedirectToRoute('login');

    $this->assertDatabaseHas('failed_jobs', ['uuid' => $job]);
    $this->assertDatabaseCount('jobs', 0);
})->with('failed job routes');

it('requires email verification before viewing or changing a failed job', function (string $method, string $route) {
    $owner = User::factory()->unverified()->create();
    config(['operations.owner_uuid' => $owner->user_id]);
    $job = recordedFailedJob();

    $this->actingAs($owner)->call($method, route($route, $job))->assertRedirectToRoute('verification.notice');

    $this->assertDatabaseHas('failed_jobs', ['uuid' => $job]);
    $this->assertDatabaseCount('jobs', 0);
})->with('failed job routes');

it('refuses failed job access to ordinary users', function (string $method, string $route) {
    failedJobOwner();
    $user = User::factory()->create();
    $job = recordedFailedJob();

    $this->actingAs($user)->call($method, route($route, $job))->assertForbidden();

    $this->assertDatabaseHas('failed_jobs', ['uuid' => $job]);
    $this->assertDatabaseCount('jobs', 0);
})->with('failed job routes');

it('links all three actions from the owner backlog without exposing exception messages there', function () {
    $owner = failedJobOwner();
    $job = recordedFailedJob();

    $this->actingAs($owner)->get(route('owner.queue-backlog'))
        ->assertSee('View')->assertSee('Requeue')->assertSee('Discard')
        ->assertSee('action="'.route('owner.failed-jobs.show', $job).'"', false)
        ->assertSee('action="'.route('owner.failed-jobs.retry', $job).'"', false)
        ->assertSee('name="_method" value="DELETE"', false)
        ->assertDontSee('Feature replay exceeded its timeout');
});

it('shows public market details from serialized jobs without showing the payload', function () {
    $owner = failedJobOwner();
    recordedFailedJob();

    $this->actingAs($owner)->get(route('owner.queue-backlog'))
        ->assertOk()->assertSee('Exchange')->assertSee('Market')->assertSee('Period')
        ->assertSee('kraken')->assertSee('BTC/USD')->assertSee('15m')
        ->assertDontSee('O:');
});

it('sorts failed job headers in both directions and shows exception names', function (string $sort, string $direction, array $order) {
    $owner = failedJobOwner();
    $ids = [
        'a' => recordedFailedJob([
            'uuid' => '00000000-0000-4000-8000-000000000001', 'connection' => 'redis', 'queue' => 'zeta',
            'exception' => "ZetaException: PRIVATE ZETA MESSAGE\nStack trace:\n#0 /app/zeta.php(1)", 'failed_at' => '2026-10-05 10:00:00',
        ]),
        'b' => recordedFailedJob([
            'uuid' => '00000000-0000-4000-8000-000000000002', 'connection' => 'database', 'queue' => 'beta',
            'exception' => "AlphaException: PRIVATE ALPHA MESSAGE\nStack trace:\n#0 /app/alpha.php(1)", 'failed_at' => '2026-10-05 12:00:00',
        ]),
        'c' => recordedFailedJob([
            'uuid' => '00000000-0000-4000-8000-000000000003', 'connection' => 'redis', 'queue' => 'alpha',
            'exception' => "BetaException: PRIVATE BETA MESSAGE\nStack trace:\n#0 /app/beta.php(1)", 'failed_at' => '2026-10-05 11:00:00',
        ]),
    ];

    $response = $this->actingAs($owner)->get(route('owner.queue-backlog', [
        'failed_sort' => $sort, 'failed_direction' => $direction,
    ]));

    $response->assertOk()
        ->assertViewHas('failed', fn ($failed) => $failed->pluck('uuid')->all() === array_map(fn ($key) => $ids[$key], $order))
        ->assertSee('aria-sort="'.($direction === 'asc' ? 'ascending' : 'descending').'"', false)
        ->assertSee('ZetaException')->assertSee('AlphaException')->assertSee('BetaException')
        ->assertDontSee('PRIVATE ZETA MESSAGE')->assertDontSee('PRIVATE ALPHA MESSAGE')->assertDontSee('PRIVATE BETA MESSAGE');
})->with([
    'UUID ascending' => ['uuid', 'asc', ['a', 'b', 'c']],
    'UUID descending' => ['uuid', 'desc', ['c', 'b', 'a']],
    'connection ascending' => ['connection', 'asc', ['b', 'c', 'a']],
    'connection descending' => ['connection', 'desc', ['a', 'c', 'b']],
    'exception ascending' => ['exception', 'asc', ['b', 'c', 'a']],
    'exception descending' => ['exception', 'desc', ['a', 'c', 'b']],
    'date ascending' => ['failed_at', 'asc', ['a', 'c', 'b']],
    'date descending' => ['failed_at', 'desc', ['b', 'c', 'a']],
]);

it('rejects invalid failed job sorting parameters', function (string $field, string $value) {
    $this->actingAs(failedJobOwner())->from('/owner/queue-backlog')->get(route('owner.queue-backlog', [$field => $value]))
        ->assertRedirect('/owner/queue-backlog')->assertSessionHasErrors($field);
})->with([
    'sort expression' => ['failed_sort', 'failed_at desc; DROP TABLE failed_jobs'],
    'direction expression' => ['failed_direction', 'desc, uuid'],
]);

it('shows escaped failure details without executing or revealing the serialized payload', function () {
    $owner = failedJobOwner();
    $exception = "RuntimeException: <script>alert('failure')</script>\nStack trace:\n#0 /app/Jobs/BuildMarketFeatures.php(50)";
    $job = recordedFailedJob([
        'exception' => $exception,
        'payload' => json_encode(['displayName' => '<b>BuildMarketFeatures</b>', 'data' => ['command' => 'PRIVATE_SERIALIZED_PAYLOAD']]),
    ]);

    $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => 0])
        ->get(route('owner.failed-jobs.show', $job))
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertSee($job)->assertSee('database')->assertSee('features')
        ->assertSee($exception)->assertSee('<b>BuildMarketFeatures</b>')
        ->assertDontSee("<script>alert('failure')</script>", false)
        ->assertDontSee('<b>BuildMarketFeatures</b>', false)
        ->assertDontSee('PRIVATE_SERIALIZED_PAYLOAD');

    $this->assertDatabaseHas('failed_jobs', ['uuid' => $job]);
    $this->assertDatabaseCount('jobs', 0);
});

it('can inspect a failure even when its payload is malformed', function () {
    $owner = failedJobOwner();
    $job = recordedFailedJob(['payload' => '{broken-json']);

    $this->actingAs($owner)->get(route('owner.failed-jobs.show', $job))
        ->assertSee('Unknown job')->assertSee('Feature replay exceeded its timeout');
});

it('requeues exactly the selected job on its original connection and queue with fresh attempts', function () {
    $owner = failedJobOwner();
    config(['queue.connections.history' => [...config('queue.connections.database'), 'table' => 'jobs', 'queue' => 'default']]);
    $job = recordedFailedJob(['connection' => 'history', 'queue' => 'history-recovery']);
    $other = recordedFailedJob();
    $original = json_decode(DB::table('failed_jobs')->where('uuid', $job)->value('payload'), true);

    $this->actingAs($owner)->post(route('owner.failed-jobs.retry', $job), [
        'connection' => 'sync', 'queue' => 'forged', 'id' => 'all',
    ])->assertRedirectToRoute('owner.queue-backlog')->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'Job '.$job.' requeued on history / history-recovery.');

    $this->assertDatabaseMissing('failed_jobs', ['uuid' => $job]);
    $this->assertDatabaseHas('failed_jobs', ['uuid' => $other]);
    $this->assertDatabaseCount('jobs', 1);
    $this->assertDatabaseHas('jobs', ['queue' => 'history-recovery', 'attempts' => 0, 'reserved_at' => null]);
    $queued = json_decode(DB::table('jobs')->value('payload'), true);
    expect($queued)->toMatchArray([...$original, 'attempts' => 0]);

    $this->post(route('owner.failed-jobs.retry', $job))->assertSessionHasErrors('failed_job');
    $this->assertDatabaseCount('jobs', 1);
});

it('discards only the selected failure without changing queued jobs', function () {
    $owner = failedJobOwner();
    $job = recordedFailedJob();
    $other = recordedFailedJob();
    Queue::connection('database')->push(new BuildMarketFeatures('kraken', 'ETH/USD', '15m'), '', 'features');
    $pending = DB::table('jobs')->first();

    $this->actingAs($owner)->delete(route('owner.failed-jobs.destroy', $job))
        ->assertRedirectToRoute('owner.queue-backlog')->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'Failed job '.$job.' discarded without running it.');

    $this->assertDatabaseMissing('failed_jobs', ['uuid' => $job]);
    $this->assertDatabaseHas('failed_jobs', ['uuid' => $other]);
    $this->assertDatabaseCount('jobs', 1);
    $this->assertDatabaseHas('jobs', (array) $pending);
});

it('retains the failed job when its original queue cannot accept the retry', function () {
    Exceptions::fake();
    $owner = failedJobOwner();
    config(['queue.connections.broken' => [...config('queue.connections.database'), 'table' => 'missing_jobs_table']]);
    $job = recordedFailedJob(['connection' => 'broken']);

    $this->actingAs($owner)->post(route('owner.failed-jobs.retry', $job))
        ->assertRedirectToRoute('owner.queue-backlog')
        ->assertSessionHasErrors(['failed_job' => 'The job could not be requeued. Check the server log for details before trying again.']);

    $this->assertDatabaseHas('failed_jobs', ['uuid' => $job]);
    $this->assertDatabaseCount('jobs', 0);
    Exceptions::assertReported(QueryException::class);
});

it('keeps invalid job payloads out of the queue without losing their failure records', function (string $payload, string $exceptionClass) {
    Exceptions::fake();
    $owner = failedJobOwner();
    $job = recordedFailedJob(['payload' => $payload]);

    $this->actingAs($owner)->post(route('owner.failed-jobs.retry', $job))->assertSessionHasErrors('failed_job');

    $this->assertDatabaseHas('failed_jobs', ['uuid' => $job, 'payload' => $payload]);
    $this->assertDatabaseCount('jobs', 0);
    Exceptions::assertReported($exceptionClass);
})->with(['malformed JSON' => ['{broken-json', JsonException::class], 'invalid job' => ['{"job":null}', RuntimeException::class]]);

it('refuses to execute retries inline or silently drop them', function (string $connection) {
    $owner = failedJobOwner();
    config(['queue.connections.discarded' => ['driver' => 'null']]);
    $job = recordedFailedJob(['connection' => $connection]);

    $this->actingAs($owner)->post(route('owner.failed-jobs.retry', $job))
        ->assertSessionHasErrors(['failed_job' => 'This job’s original connection is not configured as a supported background queue. The failure record was kept.']);

    $this->assertDatabaseHas('failed_jobs', ['uuid' => $job]);
    $this->assertDatabaseCount('jobs', 0);
})->with(['sync', 'discarded', 'removed-connection']);

it('blocks overlapping actions on the same failed job', function (string $method, string $route) {
    $owner = failedJobOwner();
    $job = recordedFailedJob();
    $lock = Cache::lock('owner:failed-job:'.$job, 300);
    $lock->get();

    try {
        $this->actingAs($owner)->call($method, route($route, $job))
            ->assertSessionHasErrors(['failed_job' => 'Another action is already in progress for this failed job. Refresh the backlog before trying again.']);

        $this->assertDatabaseHas('failed_jobs', ['uuid' => $job]);
        $this->assertDatabaseCount('jobs', 0);
    } finally {
        $lock->release();
    }
})->with('failed job mutations');

it('completes the selected action directly after password confirmation', function (string $method, string $route, int $queued) {
    $owner = failedJobOwner();
    $job = recordedFailedJob();
    $url = route($route, $job);
    $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => 0]);

    $confirmation = $this->call($method, $url);

    $confirmation->assertOk()->assertViewIs('auth.confirm-password')->assertSee('action="'.$url.'"', false);
    $this->assertDatabaseHas('failed_jobs', ['uuid' => $job]);
    $this->assertDatabaseCount('jobs', 0);

    $this->post($confirmation->viewData('formAction'), [
        ...$confirmation->viewData('formFields'), 'password' => 'password',
    ])->assertRedirectToRoute('owner.queue-backlog')->assertSessionHasNoErrors();

    $this->assertDatabaseMissing('failed_jobs', ['uuid' => $job]);
    $this->assertDatabaseCount('jobs', $queued);
})->with('failed job mutations');

it('rejects an incorrect password without changing the failed job', function (string $method, string $route) {
    $owner = failedJobOwner();
    $job = recordedFailedJob();

    $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => 0])
        ->call($method, route($route, $job), ['password' => 'wrong-password'])
        ->assertUnprocessable()->assertSee('The password is incorrect.')
        ->assertSessionHas('auth.password_confirmed_at', 0)->assertSessionMissing('_old_input.password');

    $this->assertDatabaseHas('failed_jobs', ['uuid' => $job]);
    $this->assertDatabaseCount('jobs', 0);
})->with('failed job mutations');

it('requires recent password confirmation for JSON mutations', function (string $method, string $route) {
    $owner = failedJobOwner();
    $job = recordedFailedJob();

    $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => 0])
        ->json($method, route($route, $job))->assertStatus(423)->assertJsonPath('message', 'Password confirmation required.');

    $this->assertDatabaseHas('failed_jobs', ['uuid' => $job]);
    $this->assertDatabaseCount('jobs', 0);
})->with('failed job mutations');

it('rejects bulk keywords in routes so a row action cannot target every failed job', function (string $method, string $route) {
    $owner = failedJobOwner();
    $job = recordedFailedJob();

    $this->actingAs($owner)->call($method, route($route, 'all'))->assertNotFound();

    $this->assertDatabaseHas('failed_jobs', ['uuid' => $job]);
    $this->assertDatabaseCount('jobs', 0);
})->with('failed job routes');

it('returns not found when viewing a failure that was already removed', function () {
    $owner = failedJobOwner();

    $this->actingAs($owner)->get(route('owner.failed-jobs.show', (string) Str::uuid()))->assertNotFound();
});

it('reports stale row actions without affecting other failed jobs', function (string $method, string $route) {
    $owner = failedJobOwner();
    $other = recordedFailedJob();

    $this->actingAs($owner)->call($method, route($route, (string) Str::uuid()))
        ->assertRedirectToRoute('owner.queue-backlog')
        ->assertSessionHasErrors(['failed_job' => 'This failed job no longer exists. It may already have been requeued or discarded.']);

    $this->assertDatabaseHas('failed_jobs', ['uuid' => $other]);
    $this->assertDatabaseCount('jobs', 0);
})->with('failed job mutations');
