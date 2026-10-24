<?php

use App\Domain\Operations\QueueAdministration;
use App\Models\User;

function queueManagementOwner(): User
{
    $owner = User::factory()->create();
    config(['operations.owner_uuid' => $owner->user_id]);

    return $owner;
}

function queueManagementSelection(): array
{
    return ['connection' => 'redis', 'queue' => 'features'];
}

function queueManagementJob(array $overrides = []): array
{
    return array_replace([
        'uuid' => 'ba7dc038-1bf1-4a41-bb1e-995e0491b90e',
        'name' => 'BuildMarketFeatures', 'state' => 'waiting',
        'exchange' => 'kraken', 'symbol' => 'BTC/USD', 'period' => '1h',
        'subscriber_count' => 10, 'created_at' => 1700000000, 'available_at' => null,
        'attempts' => 0, 'priority_reason' => '10 active subscribers', 'manual' => null,
        'can_cancel' => true, 'can_move' => true, 'cancel_reason' => null,
        'options' => ['chunkCandles' => 1000],
    ], $overrides);
}

it('requires sign-in to inspect queued work', function () {
    $this->get(route('owner.queue-jobs.index', queueManagementSelection()))
        ->assertRedirectToRoute('login');
});

it('refuses queue administration to ordinary users', function (string $method, string $route) {
    queueManagementOwner();
    $user = User::factory()->create();
    $job = queueManagementJob();
    $this->mock(QueueAdministration::class)->shouldNotReceive('snapshot', 'inspect', 'move', 'cancel');

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
        ->call($method, route($route, ['job' => $job['uuid'], ...queueManagementSelection()]), ['direction' => 'top'])
        ->assertForbidden();
})->with([
    ['GET', 'owner.queue-jobs.show'],
    ['POST', 'owner.queue-jobs.move'],
    ['DELETE', 'owner.queue-jobs.destroy'],
]);

it('refuses suspended owners access to pending jobs', function () {
    $owner = queueManagementOwner();
    $owner->forceFill(['suspended_at' => now()])->save();

    $this->actingAs($owner)->get(route('owner.queue-jobs.index', queueManagementSelection()))->assertForbidden();
});

it('shows subscriber counts and manual controls in queue execution order', function () {
    $owner = queueManagementOwner();
    $job = queueManagementJob();
    $this->mock(QueueAdministration::class)->shouldReceive('snapshot')->once()->with('redis', 'features', 'waiting', 1)
        ->andReturn(['jobs' => [$job], 'total' => 1, 'page' => 1, 'per_page' => 50]);

    $this->actingAs($owner)->get(route('owner.queue-jobs.index', queueManagementSelection()))
        ->assertOk()->assertSee('10 active subscribers')->assertSee('BTC/USD')->assertSee('Shown in execution order')
        ->assertSee('value="top"', false)->assertSee('value="up"', false)
        ->assertSee('value="down"', false)->assertSee('value="bottom"', false);
});

it('shows reserved work without move or cancellation controls', function () {
    $owner = queueManagementOwner();
    $job = queueManagementJob(['state' => 'reserved', 'can_cancel' => false, 'can_move' => false]);
    $this->mock(QueueAdministration::class)->shouldReceive('inspect')->once()
        ->with('redis', 'features', $job['uuid'])->andReturn($job);

    $this->actingAs($owner)->get(route('owner.queue-jobs.show', ['job' => $job['uuid'], ...queueManagementSelection()]))
        ->assertOk()->assertSee('already been claimed')->assertSee('View worker activity')
        ->assertDontSee('Cancel pending job')->assertDontSee('name="direction"', false);
});

it('escapes job details and renders only the queue administration public fields', function () {
    $owner = queueManagementOwner();
    $job = queueManagementJob(['symbol' => '<script>alert(1)</script>', 'options' => ['source' => '<script>secret()</script>']]);
    $this->mock(QueueAdministration::class)->shouldReceive('inspect')->once()->andReturn($job);

    $this->actingAs($owner)->get(route('owner.queue-jobs.show', ['job' => $job['uuid'], ...queueManagementSelection()]))
        ->assertOk()->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
        ->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('<script>secret()</script>', false);
});

it('rejects invalid queue selectors and move directions before changing work', function (array $input, string $field) {
    $owner = queueManagementOwner();
    $this->mock(QueueAdministration::class)->shouldNotReceive('move');

    $this->actingAs($owner)->postJson(route('owner.queue-jobs.move', queueManagementJob()['uuid']),
        array_replace(queueManagementSelection(), ['direction' => 'top'], $input))
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'unconfigured connection' => [['connection' => 'other-database'], 'connection'],
    'unsafe queue selector' => [['queue' => '*'], 'queue'],
    'unknown direction' => [['direction' => 'execute-now'], 'direction'],
]);

it('moves a ready job and can restore its automatic priority', function (string $direction) {
    $owner = queueManagementOwner();
    $job = queueManagementJob();
    $this->mock(QueueAdministration::class)->shouldReceive('move')->once()->with('redis', 'features', $job['uuid'], $direction)->andReturnTrue();

    $this->actingAs($owner)->post(route('owner.queue-jobs.move', $job['uuid']), [...queueManagementSelection(), 'direction' => $direction])
        ->assertRedirectToRoute('owner.queue-jobs.index', queueManagementSelection())->assertSessionHas('status');
})->with(['top', 'up', 'down', 'bottom', 'automatic']);

it('returns a conflict when a worker wins the move race', function () {
    $owner = queueManagementOwner();
    $job = queueManagementJob();
    $this->mock(QueueAdministration::class)->shouldReceive('move')->once()->andReturnFalse();

    $this->actingAs($owner)->postJson(route('owner.queue-jobs.move', $job['uuid']), [...queueManagementSelection(), 'direction' => 'top'])
        ->assertConflict()->assertJsonPath('message', 'This job could not be moved. It may have been claimed or its queue order changed. Refresh the queue before trying again.');
});

it('cancels a pending job through the owner action', function () {
    $owner = queueManagementOwner();
    $job = queueManagementJob();
    $this->mock(QueueAdministration::class)->shouldReceive('cancel')->once()->with('redis', 'features', $job['uuid'])->andReturnTrue();

    $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])
        ->delete(route('owner.queue-jobs.destroy', $job['uuid']), queueManagementSelection())
        ->assertRedirectToRoute('owner.queue-jobs.index', queueManagementSelection())
        ->assertSessionHas('status', 'Pending job '.$job['uuid'].' cancelled.');
});

it('returns a conflict instead of cancelling work that has already been claimed', function () {
    $owner = queueManagementOwner();
    $job = queueManagementJob();
    $this->mock(QueueAdministration::class)->shouldReceive('cancel')->once()->andReturnFalse();

    $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])
        ->deleteJson(route('owner.queue-jobs.destroy', $job['uuid']), queueManagementSelection())->assertConflict();
});

it('preserves the selected queue while asking for cancellation password confirmation', function () {
    $owner = queueManagementOwner();
    $job = queueManagementJob();
    $this->mock(QueueAdministration::class)->shouldNotReceive('cancel');

    $this->actingAs($owner)->delete(route('owner.queue-jobs.destroy', $job['uuid']), queueManagementSelection())
        ->assertOk()->assertSee('Confirm and cancel pending job')
        ->assertSee('name="connection" value="redis"', false)->assertSee('name="queue" value="features"', false)
        ->assertSee('name="_method" value="DELETE"', false);
});

it('returns 404 when a job has left the queue', function () {
    $owner = queueManagementOwner();
    $this->mock(QueueAdministration::class)->shouldReceive('inspect')->once()->andReturnNull();

    $this->actingAs($owner)->get(route('owner.queue-jobs.show', ['job' => queueManagementJob()['uuid'], ...queueManagementSelection()]))
        ->assertNotFound();
});

it('allows inspection of legacy jobs identified by a payload digest', function () {
    $owner = queueManagementOwner();
    $job = queueManagementJob(['uuid' => hash('sha256', 'legacy payload'), 'can_cancel' => false]);
    $this->mock(QueueAdministration::class)->shouldReceive('inspect')->once()
        ->with('redis', 'features', $job['uuid'])->andReturn($job);

    $this->actingAs($owner)->get(route('owner.queue-jobs.show', ['job' => $job['uuid'], ...queueManagementSelection()]))
        ->assertOk()->assertSee($job['uuid'])->assertDontSee('Cancel pending job');
});

it('refuses cancellation with an incorrect confirmation password', function () {
    $owner = queueManagementOwner();
    $this->mock(QueueAdministration::class)->shouldNotReceive('cancel');

    $this->actingAs($owner)->delete(route('owner.queue-jobs.destroy', queueManagementJob()['uuid']),
        [...queueManagementSelection(), 'password' => 'incorrect-password'])
        ->assertUnprocessable()->assertSee('name="queue" value="features"', false);
});

it('executes pending cancellation after a correct in-place password confirmation', function () {
    $owner = queueManagementOwner();
    $job = queueManagementJob();
    $this->mock(QueueAdministration::class)->shouldReceive('cancel')->once()
        ->with('redis', 'features', $job['uuid'])->andReturnTrue();

    $this->actingAs($owner)->delete(route('owner.queue-jobs.destroy', $job['uuid']),
        [...queueManagementSelection(), 'password' => 'password'])
        ->assertRedirectToRoute('owner.queue-jobs.index', queueManagementSelection());
});
