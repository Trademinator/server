<?php

use App\Domain\Intelligence\BackfillIntelligence;
use App\Domain\Intelligence\TrainingLease;
use App\Domain\Intelligence\TrainingRuntime;
use App\Models\Exchange;
use App\Models\Market;
use Illuminate\Cache\DatabaseLock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['cache.default' => 'array']);
    $this->travelTo('2024-01-01 00:00:00 UTC');
});

it('renews every owned lock across its initial expiry and lets abandoned leases expire', function () {
    $clock = 0.0;
    $leases = new TrainingLease(function () use (&$clock): float {
        return $clock;
    });
    $locks = [Cache::lock('training-parent', 60), Cache::lock('training-child', 60)];
    foreach ($locks as $lock) {
        expect($lock->get())->toBeTrue();
        $leases->registerLock($lock, 60);
    }

    $this->travel(45)->seconds();
    $clock = 45;
    $leases->heartbeat();
    $this->travel(30)->seconds();
    foreach (['training-parent', 'training-child'] as $name) {
        expect(Cache::lock($name, 60)->get())->toBeFalse();
    }

    foreach ($locks as $lock) {
        $leases->unregisterLock($lock);
    }
    $this->travel(31)->seconds();
    foreach (['training-parent', 'training-child'] as $name) {
        $successor = Cache::lock($name, 60);
        expect($successor->get())->toBeTrue();
        $successor->release();
    }
});

it('aborts when another owner acquires an expired lock and never releases the successor', function () {
    $leases = new TrainingLease;
    $lock = Cache::lock('training-replaced', 60);
    expect($lock->get())->toBeTrue();
    $leases->registerLock($lock, 60);
    $this->travel(61)->seconds();
    $successor = Cache::lock('training-replaced', 60);
    expect($successor->get())->toBeTrue();

    expect(fn () => $leases->heartbeat(true))->toThrow(RuntimeException::class, 'lock ownership was lost');
    $leases->unregisterLock($lock);
    expect($lock->release())->toBeFalse();
    expect($successor->isOwnedByCurrentProcess())->toBeTrue();
    expect(Cache::lock('training-replaced', 60)->get())->toBeFalse();
    $successor->release();
});

it('does not revive an expired lease even when no successor has claimed it', function () {
    $leases = new TrainingLease;
    $lock = Cache::lock('training-expired', 60);
    expect($lock->get())->toBeTrue();
    $leases->registerLock($lock, 60);
    $this->travel(61)->seconds();

    expect(fn () => $leases->heartbeat(true))->toThrow(RuntimeException::class, 'lock ownership was lost');
    $leases->unregisterLock($lock);
    expect(fn () => $leases->registerLock($lock, 60))->toThrow(RuntimeException::class, 'lock ownership was lost');
    $successor = Cache::lock('training-expired', 60);
    expect($successor->get())->toBeTrue();
    $successor->release();
});

it('handles unchanged-row database refreshes in the same second without accepting expired or replaced leases', function () {
    $leases = new TrainingLease;
    $lock = new class(DB::connection(), 'cache_locks', 'training-database', 60) extends DatabaseLock
    {
        public function refresh($seconds = null)
        {
            // Model MySQL changed-row reporting while exercising the real
            // database lock's ownership and expiry predicates on SQLite.
            $before = $this->connection->table($this->table)->where('key', $this->name)->value('expiration');
            $refreshed = parent::refresh($seconds);
            $after = $this->connection->table($this->table)->where('key', $this->name)->value('expiration');

            return $refreshed && $before !== $after;
        }
    };
    expect($lock->get())->toBeTrue();
    $leases->registerLock($lock, 60);
    expect(DB::table('cache_locks')->where('key', 'training-database')->value('expiration'))->toBe(now()->timestamp + 61);
    $leases->heartbeat(true);
    expect(DB::table('cache_locks')->where('key', 'training-database')->value('expiration'))->toBe(now()->timestamp + 60);
    $leases->heartbeat(true);
    expect(DB::table('cache_locks')->where('key', 'training-database')->value('expiration'))->toBe(now()->timestamp + 61);

    $this->travel(62)->seconds();
    expect(fn () => $leases->heartbeat(true))->toThrow(RuntimeException::class, 'lock ownership was lost');
    $successor = new DatabaseLock(DB::connection(), 'cache_locks', 'training-database', 60);
    expect($successor->get())->toBeTrue();
    expect(fn () => $leases->heartbeat(true))->toThrow(RuntimeException::class, 'lock ownership was lost');
    $leases->unregisterLock($lock);
    expect($lock->release())->toBeFalse();
    expect($successor->isOwnedByCurrentProcess())->toBeTrue();
    $successor->release();
});

it('throttles renewal callbacks and stops renewing as soon as they are unregistered', function () {
    $clock = 0.0;
    $renewals = 0;
    $leases = new TrainingLease(function () use (&$clock): float {
        return $clock;
    });
    $leases->registerLease('owned-database-row', function () use (&$renewals): bool {
        $renewals++;

        return true;
    });

    $leases->heartbeat();
    $clock = 29;
    $leases->heartbeat();
    expect($renewals)->toBe(0);
    $clock = 30;
    $leases->heartbeat();
    expect($renewals)->toBe(1);
    $leases->heartbeat(true);
    expect($renewals)->toBe(2);
    $leases->unregisterLease('owned-database-row');
    $clock = 60;
    $leases->heartbeat(true);
    expect($renewals)->toBe(2);
});

it('pulses registered leases when a new algorithm starts without changing an explicit outer deadline', function () {
    $clock = 0.0;
    $renewals = 0;
    app()->instance(TrainingLease::class, new TrainingLease(function () use (&$clock): float {
        return $clock;
    }));
    TrainingRuntime::registerLease('algorithm-boundary', function () use (&$renewals): bool {
        $renewals++;

        return true;
    });
    $clock = 30;
    $expired = microtime(true) - 1;

    expect(TrainingRuntime::algorithmDeadline($expired))->toBe($expired);
    expect($renewals)->toBe(1);
    TrainingRuntime::unregisterLease('algorithm-boundary');
});

it('keeps lock expiry tied to the longest individual stage instead of the total model search', function () {
    config([
        'intelligence.max_seconds' => 86400, 'intelligence.job_timeout' => 50_000_000,
        'research.dataset_max_seconds' => 540, 'human_training.publication_reserve_seconds' => 10,
        'lead_lag.max_peers' => 64, 'lead_lag.max_lag' => 120,
    ]);

    expect(TrainingRuntime::lockSeconds())->toBe(87000);
    expect(TrainingRuntime::jobTimeout())->toBeGreaterThan(TrainingRuntime::lockSeconds() * 100);
    config(['research.dataset_max_seconds' => 90000]);
    expect(TrainingRuntime::lockSeconds())->toBe(90600);
    config(['human_training.publication_reserve_seconds' => 100000]);
    expect(TrainingRuntime::lockSeconds())->toBe(100600);
});

function trainingLeaseRow(): array
{
    $exchange = Exchange::query()->create(['name' => 'Kraken', 'class' => 'kraken', 'config' => '{}']);
    $market = Market::query()->create(['exchange_id' => $exchange->exchange_id, 'symbol' => 'BTC/USD', 'tick_size' => '0.01']);
    $id = (string) Str::uuid7();
    $token = (string) Str::uuid7();
    DB::table('market_history_backfills')->insert([
        'history_id' => $id, 'market_id' => $market->market_id, 'period' => '1m',
        'build_lease_token' => $token, 'build_stage' => 'knn',
        'history_revision' => 1, 'build_revision' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return [$id, $token];
}

it('renews a running backfill row past its initial expiry and aborts after its token is replaced', function () {
    config(['intelligence.max_seconds' => 60, 'research.dataset_max_seconds' => 540]);
    [$id, $token] = trainingLeaseRow();
    $builds = app(BackfillIntelligence::class);
    expect($builds->renew($id, $token))->toBeTrue();
    expect($builds->renew($id, $token, allowWaiting: false))->toBeTrue();
    $initialExpiry = now()->addSeconds(TrainingRuntime::lockSeconds());
    $leases = new TrainingLease;
    $leases->registerLease('backfill', fn (): bool => $builds->renew($id, $token, allowWaiting: false));

    $this->travel(1000)->seconds();
    $leases->heartbeat(true);
    $this->travel(200)->seconds();
    expect(now()->greaterThan($initialExpiry))->toBeTrue();
    $builds->assertRunning($id, $token);
    $this->assertDatabaseHas('market_history_backfills', [
        'history_id' => $id, 'build_lease_token' => $token, 'build_lease_until' => '2024-01-01 00:35:40',
    ]);

    $successor = (string) Str::uuid7();
    DB::table('market_history_backfills')->where('history_id', $id)->update(['build_lease_token' => $successor]);
    expect(fn () => $leases->heartbeat(true))->toThrow(RuntimeException::class, 'lease ownership was lost');
    $leases->unregisterLease('backfill');
    $builds->release($id, $token);
    $this->assertDatabaseHas('market_history_backfills', ['history_id' => $id, 'build_lease_token' => $successor]);
});

it('does not restart waiting or expired backfill leases from a heartbeat', function () {
    config(['intelligence.max_seconds' => 60, 'research.dataset_max_seconds' => 540]);
    [$id, $token] = trainingLeaseRow();
    $builds = app(BackfillIntelligence::class);
    expect($builds->renew($id, $token, allowWaiting: false))->toBeFalse();
    $this->assertDatabaseHas('market_history_backfills', ['history_id' => $id, 'build_lease_until' => null]);
    expect($builds->renew($id, $token))->toBeTrue();
    $this->travel(TrainingRuntime::lockSeconds() + 1)->seconds();

    expect($builds->renew($id, $token, allowWaiting: false))->toBeFalse();
    expect($builds->renew($id, $token))->toBeFalse();
    expect(fn () => $builds->assertRunning($id, $token))->toThrow(RuntimeException::class, 'lease ownership was lost');
});

it('does no work when no leases are registered', function () {
    $leases = new TrainingLease(fn () => throw new RuntimeException('An empty registry must not read its clock.'));
    $leases->heartbeat(true);
    expect(app()->bound(TrainingLease::class))->toBeFalse();
    TrainingRuntime::heartbeat(true);
    expect(app()->bound(TrainingLease::class))->toBeFalse();
});
