<?php

use App\Domain\MarketData\MarketFeedDispatcher;
use App\Jobs\CollectMarketFeed;
use App\Models\Exchange;
use App\Models\Market;
use App\Models\MarketFeed;
use App\Models\MarketSubscription;
use App\Models\User;
use Illuminate\Support\Facades\Bus;

function prioritizedFeed(Exchange $exchange, string $symbol, int $subscribers, int $dueSecondsAgo): MarketFeed
{
    $market = Market::query()->create(['exchange_id' => $exchange->exchange_id, 'symbol' => $symbol, 'tick_size' => '0.01']);
    $feed = MarketFeed::query()->create(['market_id' => $market->market_id, 'selected_period' => '1m',
        'next_pull_at' => now()->subSeconds($dueSecondsAgo), 'status' => 'ready']);
    foreach (User::factory()->count($subscribers)->create() as $user) {
        MarketSubscription::query()->create(['market_id' => $market->market_id, 'user_id' => $user->user_id, 'active' => true]);
    }

    return $feed;
}

it('prefers more active subscribers among due feeds and carries their original waiting time', function () {
    $this->freezeTime();
    config(['queue_management.feed_max_wait_seconds' => 300]);
    Bus::fake([CollectMarketFeed::class]);
    $exchange = Exchange::query()->create(['class' => 'kraken', 'name' => 'Kraken', 'config' => '{}']);
    $low = prioritizedFeed($exchange, 'ETH/USD', 1, 120);
    $high = prioritizedFeed($exchange, 'BTC/USD', 10, 60);

    expect(app(MarketFeedDispatcher::class)->dispatchDue(1))->toBe(1);

    Bus::assertDispatched(CollectMarketFeed::class, fn ($job) => $job->marketId === $high->market_id
        && $job->queueRequestedAt === $high->next_pull_at->getTimestamp());
    expect($low->fresh()->lease_token)->toBeNull();
});

it('promotes overdue low-demand work ahead of newly due popular feeds', function () {
    $this->freezeTime();
    config(['queue_management.feed_max_wait_seconds' => 300]);
    Bus::fake([CollectMarketFeed::class]);
    $exchange = Exchange::query()->create(['class' => 'kraken', 'name' => 'Kraken', 'config' => '{}']);
    $old = prioritizedFeed($exchange, 'ETH/USD', 1, 360);
    prioritizedFeed($exchange, 'BTC/USD', 10, 60);

    expect(app(MarketFeedDispatcher::class)->dispatchDue(1))->toBe(1);
    Bus::assertDispatched(CollectMarketFeed::class, fn ($job) => $job->marketId === $old->market_id);
});

it('does not let subscriber demand bypass due time or include suspended users', function () {
    $this->freezeTime();
    Bus::fake([CollectMarketFeed::class]);
    $exchange = Exchange::query()->create(['class' => 'kraken', 'name' => 'Kraken', 'config' => '{}']);
    $due = prioritizedFeed($exchange, 'ETH/USD', 1, 60);
    prioritizedFeed($exchange, 'BTC/USD', 10, -3600);
    $suspended = prioritizedFeed($exchange, 'SOL/USD', 1, 600);
    User::query()->whereIn('user_id', MarketSubscription::query()->where('market_id', $suspended->market_id)->select('user_id'))
        ->update(['suspended_at' => now()]);

    expect(app(MarketFeedDispatcher::class)->dispatchDue(5))->toBe(1);
    Bus::assertDispatched(CollectMarketFeed::class, fn ($job) => $job->marketId === $due->market_id);
    expect(app(MarketFeedDispatcher::class)->dispatchOne($suspended->market_id, true))->toBeFalse();
});
