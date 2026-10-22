<?php

namespace App\Http\Controllers\Owner;

use App\Domain\Intelligence\KnowledgeRequestJournal;
use App\Domain\Intelligence\ModelStore;
use App\Domain\MarketData\CandleTimeframe;
use App\Domain\MarketData\MarketFeedDispatcher;
use App\Domain\Operations\ActionLog;
use App\Http\Controllers\Controller;
use App\Jobs\BuildMarketFeatures;
use App\Jobs\BuildOwnerDatasetSnapshot;
use App\Models\Market;
use App\Repositories\TickerRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Only dispatch pre-approved knowledge operations; never accept command text. */
final class KnowledgeActionController extends Controller
{
    public function store(Request $request, Market $market, MarketFeedDispatcher $feeds): RedirectResponse
    {
        Gate::authorize('manage-server');
        $input = $request->validate(['action' => ['required', Rule::in([
            'collect', 'features', 'labels', 'snapshot', 'train',
        ])]]);
        $action = $input['action'];
        $market->loadMissing('exchange', 'feed');
        $period = $market->feed?->selected_period;
        if ($period === null || ! $market->subscriptions()->where('active', true)->exists()) {
            throw ValidationException::withMessages(['action' => 'Knowledge actions require an active subscribed market and selected candle period.']);
        }
        if (in_array(config('queue.default'), ['sync', 'null'], true)) {
            throw ValidationException::withMessages(['action' => 'A persistent queue connection is required.']);
        }

        $exchange = $market->exchange->class;
        $symbol = $market->symbol;
        $scope = ModelStore::marketKey($exchange, $symbol, $period);
        $requestKey = 'trademinator:owner-knowledge-request:'.$scope.':'.$action;
        if (! Cache::add($requestKey, true, now()->addMinutes($action === 'train' ? 30 : 5))) {
            return back()->with('status', 'A recent request for this stage is already queued or processing.');
        }
        try {
            $queued = match ($action) {
                'collect' => $feeds->dispatchOne($market->market_id, force: true),
                'features' => $this->features($exchange, $symbol, $period),
                'labels' => $this->labels($exchange, $symbol, $period, $scope),
                'snapshot' => $this->snapshot($exchange, $symbol, $period),
                'train' => $this->train($exchange, $symbol, $period),
            };
            if (! $queued) {
                Cache::forget($requestKey);

                return back()->with('status', 'The stage is already claimed by another worker. No duplicate job was created.');
            }
        } catch (Throwable $error) {
            Cache::forget($requestKey);
            throw $error;
        }

        app(ActionLog::class)->write('knowledge.owner_action_requested', [
            'market_id' => $market->market_id, 'exchange' => $exchange, 'symbol' => $symbol,
            'period' => $period, 'action' => $action, 'requested_by' => $request->user()?->getAuthIdentifier(),
            'outcome' => 'queued',
        ]);

        return back()->with('status', strtoupper($action).' requested for '.$exchange.' '.$symbol.' ('.$period.'). Check the waterfall and job queue for results.');
    }

    private function latestCompletedCandle(string $exchange, string $symbol, string $period): ?int
    {
        $latest = app(TickerRepository::class)->latestTimestamp($exchange, $symbol, $period);
        if ($latest === null) {
            return null;
        }
        $timeframe = new CandleTimeframe;

        return $timeframe->next($latest, $period) <= now()->getTimestampMs()
            ? $latest : max(0, $timeframe->previous($latest, $period));
    }

    private function features(string $exchange, string $symbol, string $period): bool
    {
        app(KnowledgeRequestJournal::class)->request(
            $exchange, $symbol, $period, 'features',
            $this->latestCompletedCandle($exchange, $symbol, $period)
        );
        BuildMarketFeatures::dispatch($exchange, $symbol, $period)
            ->onQueue((string) config('features.queue', 'features'));

        return true;
    }

    private function labels(string $exchange, string $symbol, string $period, string $scope): bool
    {
        // The trainer already evaluates four learning paths sequentially.
        // Prepare mature algorithmic labels + compatible features first.
        app(KnowledgeRequestJournal::class)
            ->request($exchange, $symbol, $period, 'snapshot');
        app(KnowledgeRequestJournal::class)
            ->request($exchange, $symbol, $period, 'ai');
        BuildOwnerDatasetSnapshot::dispatch($exchange, $symbol, $period, true)
            ->onQueue((string) config('intelligence.queue'));

        return true;
    }

    private function snapshot(string $exchange, string $symbol, string $period): bool
    {
        app(KnowledgeRequestJournal::class)
            ->request($exchange, $symbol, $period, 'snapshot');
        BuildOwnerDatasetSnapshot::dispatch($exchange, $symbol, $period)
            ->onQueue((string) config('intelligence.queue'));

        return true;
    }

    private function train(string $exchange, string $symbol, string $period): bool
    {
        app(KnowledgeRequestJournal::class)
            ->request($exchange, $symbol, $period, 'snapshot');
        app(KnowledgeRequestJournal::class)
            ->request($exchange, $symbol, $period, 'ai');
        BuildOwnerDatasetSnapshot::dispatch($exchange, $symbol, $period, true)
            ->onQueue((string) config('intelligence.queue'));

        return true;
    }
}
