<?php

namespace App\Http\Controllers\Markets;

use App\Domain\Intelligence\CandleTraining;
use App\Domain\Intelligence\HumanTraining;
use App\Domain\Intelligence\HumanTrainingExport;
use App\Domain\Intelligence\OutcomeTrainingSession;
use App\Repositories\TickerRepository;
use App\Domain\MarketData\CandleTimeframe;
use App\Domain\MarketData\SubscribedPairOptions;
use App\Http\Controllers\Controller;
use App\Models\HumanCandleLabel;
use App\Models\HumanTrainingReview;
use App\Models\HumanTrainingSnapshot;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class HumanTrainingController extends Controller
{
    public function index(Request $request, HumanTraining $training, CandleTraining $candleTraining, SubscribedPairOptions $pairOptions): Response
    {
        $selection = $request->validate([
            'exchange' => ['nullable', 'string', 'max:100'],
            'symbol' => ['nullable', 'string', 'max:100'],
            'period' => ['nullable', Rule::in(CandleTimeframe::SUPPORTED)],
        ]);
        $reviews = HumanTrainingReview::query()->where('trainer_id', $request->user()->user_id);
        $pending = (clone $reviews)->whereNull('submitted_at')->where('expires_at', '>', now()->format('Y-m-d H:i:s.v'))->first();
        $subscribedMarkets = $pairOptions->markets($request->user(), allSubscribed: true);
        $datasets = $pairOptions->datasets($request->user(), $training->datasets($subscribedMarkets), allSubscribed: true);
        $selectedDataset = null;

        if (isset($selection['exchange'], $selection['symbol'], $selection['period'])) {
            foreach ($datasets as $dataset) {
                if (($dataset['exchange'] ?? null) === $selection['exchange']
                    && ($dataset['symbol'] ?? null) === $selection['symbol']
                    && ($dataset['period'] ?? null) === $selection['period']) {
                    $selectedDataset = $dataset['dataset_id'];
                    break;
                }
            }
        }

        return response()->view('markets.human-training', ['datasets' => $datasets,
            'availableMarkets' => $pairOptions->markets($request->user(), allSubscribed: Gate::allows('manage-server')), 'selectedDataset' => $selectedDataset,
            'pending' => $pending, 'completed' => (clone $reviews)->whereIn('label', HumanTraining::acceptedLabels())->count(),
            'candleCompleted' => $candleTraining->count($request->user()),
            'statistics' => Gate::allows('manage-server') ? $this->statistics() : null,
        ])->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $request, HumanTraining $training): RedirectResponse
    {
        $data = $request->validate(['dataset' => ['required', 'uuid', 'exists:research_datasets,dataset_id']]);
        $review = $training->assign($request->user(), $data['dataset']);

        return redirect()->route('human-training.show', $review->review_id);
    }

    public function show(Request $request, string $review, HumanTraining $training): Response
    {
        $item = HumanTrainingReview::query()->with('snapshot')->where('trainer_id', $request->user()->user_id)->findOrFail($review);

        $snapshot = $training->display($item);
        $futureCandle = null;
        $futureSeries = [];
        $afterSeries = [];
        $horizon = (int) ($snapshot['horizon_candles'] ?? 0);
        if ($horizon > 0 && $horizon <= 1000) {
            $timeframe = app(CandleTimeframe::class);
            $target = (int) $snapshot['microtimestamp'];
            for ($i = 0; $i < $horizon; $i++) {
                $target = $timeframe->next($target, $snapshot['period']);
            }
            if ($timeframe->next($target, $snapshot['period']) <= now()->getTimestampMs()) {
                $expected = (int) $snapshot['microtimestamp'];
                $valid = true;
                foreach (app(TickerRepository::class)->streamHistory(
                    $snapshot['exchange'], $snapshot['symbol'], $snapshot['period'],
                    $timeframe->next($expected, $snapshot['period']), $target
                ) as $candle) {
                    $expected = $timeframe->next($expected, $snapshot['period']);
                    if ((int) $candle['microtimestamp'] !== $expected) {
                        $valid = false;
                        break;
                    }
                    foreach (['open', 'high', 'low', 'close', 'volume'] as $field) {
                        if (!is_numeric($candle[$field] ?? null)) {
                            $valid = false;
                            break 2;
                        }
                    }
                    $futureSeries[] = ['time' => intdiv($expected, 1000),
                        ...array_map('strval', array_intersect_key($candle, array_flip(['open', 'high', 'low', 'close', 'volume'])))];
                }
                if ($valid && count($futureSeries) === $horizon && $expected === $target) {
                    $futureCandle = $futureSeries[array_key_last($futureSeries)];
                    // Aim for H candles following the grey bar. Only complete, real
                    // OHLCV bars may be appended; never invent missing candles.
                    $extraTarget = $target;
                    for ($i = 0; $i < $horizon; $i++) {
                        $extraTarget = $timeframe->next($extraTarget, $snapshot['period']);
                    }
                    $extraEnd = $target;
                    while ($extraEnd < $extraTarget
                        && $timeframe->next($timeframe->next($extraEnd, $snapshot['period']), $snapshot['period']) <= now()->getTimestampMs()) {
                        $extraEnd = $timeframe->next($extraEnd, $snapshot['period']);
                    }
                    if ($extraEnd > $target) {
                        $expectedExtra = $target;
                        foreach (app(TickerRepository::class)->streamHistory(
                            $snapshot['exchange'], $snapshot['symbol'], $snapshot['period'],
                            $timeframe->next($target, $snapshot['period']), $extraEnd
                        ) as $candle) {
                            $expectedExtra = $timeframe->next($expectedExtra, $snapshot['period']);
                            if ((int) $candle['microtimestamp'] !== $expectedExtra) break;
                            $fields = [];
                            foreach (['open', 'high', 'low', 'close', 'volume'] as $field) {
                                if (!is_numeric($candle[$field] ?? null)) break 2;
                                $fields[$field] = (string) $candle[$field];
                            }
                            $afterSeries[] = ['time' => intdiv($expectedExtra, 1000), ...$fields];
                        }
                    }
                } else {
                    $futureSeries = [];
                }
            }
        }
        // A recorded action is not an Outcome KNN class. Never infer a five-class
        // prediction from BUY/HOLD/SELL or reveal the later outcome as a prediction.
        $machineOutcome = null;

        return response()->view('markets.human-training-review', [
            'review' => $item, 'snapshot' => $snapshot, 'futureCandle' => $futureCandle,
            'machineOutcome' => $machineOutcome, 'futureSeries' => $futureSeries, 'afterSeries' => $afterSeries, 'labels' => HumanTraining::LABELS,
        ])->header('Cache-Control', 'no-store, private');
    }

    public function update(Request $request, string $review, HumanTraining $training, OutcomeTrainingSession $sessions): RedirectResponse
    {
        $data = $request->validate(['label' => ['required', Rule::in([...HumanTraining::LABELS, 'skip'])],
            'confidence' => ['nullable', 'integer', 'between:0,100'], 'reason' => ['nullable', 'string', 'max:2000'],
            'next' => ['sometimes', Rule::in([OutcomeTrainingSession::SAME_MARKET, OutcomeTrainingSession::LEAST_TRAINED])]]);
        $saved = $training->submit($request->user(), $review, $data['label'], isset($data['confidence']) ? (int) $data['confidence'] : null, $data['reason'] ?? null);

        // A skip also advances within the current pair; older clients without
        // an explicit continuation still reach their saved review as before.
        $mode = $data['next'] ?? ($data['label'] === 'skip' ? OutcomeTrainingSession::SAME_MARKET : null);
        if ($mode !== null) {
            $next = $sessions->next($request->user(), $saved, $mode);
            $prefix = $data['label'] === 'skip' ? 'Snapshot skipped.' : 'Outcome assessment saved.';
            if ($next !== null) {
                return redirect()->route('human-training.show', $next->review_id)
                    ->with('status', $prefix.' Here is your next scenario.');
            }

            $scope = $mode === OutcomeTrainingSession::SAME_MARKET ? 'this exchange/pair' : 'another exchange/pair';

            return redirect()->route('human-training.index')->with('status',
                $prefix.' No unseen, intact snapshot is currently available for '.$scope.'. Choose another dataset to continue.');
        }

        return redirect()->route('human-training.show', $review)->with('status', 'Trend assessment saved. It will be considered at the next model build.');
    }

    public function export(HumanTrainingExport $export): BinaryFileResponse
    {
        Gate::authorize('manage-server');
        $directory = storage_path('app/private/exports');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $path = $directory.'/human-training-'.Str::uuid7().'.jsonl';
        $export->write($path);

        return response()->download($path, basename($path), ['Cache-Control' => 'no-store, private'])->deleteFileAfterSend(true);
    }

    private function statistics(): array
    {
        $snapshots = HumanTrainingSnapshot::query()->whereHas('reviews', fn ($query) => $query->whereIn('label', HumanTraining::acceptedLabels()))
            ->with(['reviews' => fn ($query) => $query->whereIn('label', HumanTraining::acceptedLabels())])
            ->orderByDesc('decision_at_ms')->limit(1000)->get();
        $trainers = [];
        $shared = $disputed = 0;
        foreach ($snapshots as $snapshot) {
            $reviews = $snapshot->reviews;
            $votes = $reviews->countBy('label');
            $shared += (int) ($reviews->count() > 1);
            $disputed += (int) ($reviews->pluck('label')->unique()->count() > 1);
            foreach ($reviews as $review) {
                $id = $review->trainer_id;
                $trainers[$id] ??= ['labels' => 0, 'peer_comparisons' => 0, 'peer_agreements' => 0];
                $trainers[$id]['labels']++;
                $trainers[$id]['peer_comparisons'] += $reviews->count() - 1;
                $trainers[$id]['peer_agreements'] += $votes[$review->label] - 1;
            }
        }

        return ['snapshots' => $snapshots->count(), 'shared' => $shared, 'disputed' => $disputed,
            'candle_labels' => HumanCandleLabel::query()->count(), 'trainers' => $trainers];
    }
}
