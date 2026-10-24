<?php

namespace App\Http\Controllers\Markets;

use App\Domain\Intelligence\ActionLabelReportStore;
use App\Domain\Intelligence\CandleTraining;
use App\Domain\Intelligence\HumanTraining;
use App\Domain\Research\DatasetStore;
use App\Http\Controllers\Controller;
use App\Jobs\AnalyzeMarketAutoLabels;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class CandleTrainingController extends Controller
{
    public function store(Request $request, CandleTraining $training): RedirectResponse
    {
        $data = $request->validate(['dataset' => ['required', 'uuid', 'exists:research_datasets,dataset_id']]);
        $decision = $training->start($request->user(), $data['dataset']);

        return redirect()->route('human-training.candles.show', [
            'dataset' => $data['dataset'], 'decision_at_ms' => $decision,
        ]);
    }

    public function show(Request $request, string $dataset, CandleTraining $training, HumanTraining $snapshots): Response|JsonResponse
    {
        $data = $request->validate(['decision_at_ms' => ['nullable', 'integer', 'min:1']]);
        $state = $training->review($request->user(), $dataset,
            isset($data['decision_at_ms']) ? (int) $data['decision_at_ms'] : null);

        if ($request->wantsJson()) {
            return response()->json([
                'series' => $state['payload']['series'],
                'labels' => $state['visible_labels'],
                'auto_labels' => $state['auto_labels'],
                'decisions' => $state['decisions'],
                'allowed_actions' => $state['allowed_actions'],
                'decision_at_ms' => $state['payload']['decision_at_ms'],
                'earliest_decision_at_ms' => $state['earliest_window_decision_at_ms'],
                'latest_decision_at_ms' => $state['latest_decision_at_ms'],
                'has_more' => $state['has_more'],
                'has_newer' => $state['next_decision_at_ms'] !== null,
            ])->header('Cache-Control', 'no-store, private');
        }

        return response()->view('markets.candle-training', ['state' => $state,
            'datasets' => $snapshots->datasets()])->header('Cache-Control', 'no-store, private');
    }

    public function history(Request $request, string $dataset, CandleTraining $training): JsonResponse
    {
        $data = $request->validate([
            'decision_at_ms' => ['required', 'integer', 'min:1'],
            'before_ms' => ['required_without:after_ms', 'prohibits:after_ms', 'integer', 'min:1'],
            'after_ms' => ['required_without:before_ms', 'prohibits:before_ms', 'integer', 'min:1'],
        ]);

        $page = isset($data['after_ms'])
            ? $training->nextHistory($request->user(), $dataset, (int) $data['decision_at_ms'], (int) $data['after_ms'])
            : $training->history($request->user(), $dataset, (int) $data['decision_at_ms'], (int) $data['before_ms']);

        return response()->json($page)->header('Cache-Control', 'no-store, private');
    }

    public function autoLabel(Request $request, string $dataset, CandleTraining $training): JsonResponse
    {
        Gate::forUser($request->user())->authorize('train-intelligence');
        $manifest = app(DatasetStore::class)->manifest($dataset);
        if (! config('intelligence.enabled') || in_array(config('queue.default'), ['sync', 'null'], true)) {
            return response()->json(['message' => 'Intelligence queue is not enabled.'], 422);
        }
        $key = 'trademinator:action-auto-label:'.hash('sha256', implode('|', [
            $manifest['exchange'], $manifest['symbol'], $manifest['period'],
        ]));
        $lock = Cache::lock($key.':lock', 3600);
        if (! $lock->get()) {
            return response()->json(['message' => 'Auto-labelling is already queued or running.'], 409);
        }
        Cache::put($key.':status', 'queued', now()->addDay());
        Cache::forget($key.':error');
        $job = new AnalyzeMarketAutoLabels(
            $manifest['exchange'], $manifest['symbol'], $manifest['period'], $key
        );
        $job->dispatchLockOwner = $lock->owner();
        $job->onQueue(config('intelligence.queue'));
        try {
            dispatch($job);
        } catch (\Throwable $error) {
            $lock->release();
            Cache::put($key.':status', 'failed', now()->addDay());
            Cache::put($key.':error', 'Could not dispatch auto-labelling.', now()->addDay());
            throw $error;
        }

        return response()->json(['status' => 'queued',
            'message' => 'System auto-labelling queued. Human labels will not be changed.'], 202);
    }

    public function autoLabelStatus(Request $request, string $dataset): JsonResponse
    {
        Gate::forUser($request->user())->authorize('train-intelligence');
        $manifest = app(DatasetStore::class)->manifest($dataset);
        $key = 'trademinator:action-auto-label:'.hash('sha256', implode('|', [
            $manifest['exchange'], $manifest['symbol'], $manifest['period'],
        ]));
        $report = app(ActionLabelReportStore::class)->latest(
            $manifest['exchange'], $manifest['symbol'], $manifest['period']
        );
        $counts = $report['analysis']['action_counts'] ?? null;

        return response()->json([
            'status' => Cache::get($key.':status', 'idle'),
            'locked' => Cache::lock($key.':lock')->isLocked() || (bool) Cache::get($key.':lock', false),
            'error' => Cache::get($key.':error'),
            'analyzed_at' => Cache::get($key.':summary')['analyzed_at'] ?? null,
            'as_of_ms' => $report['as_of_ms'] ?? null,
            'computed_at' => $report['computed_at'] ?? null,
            'source' => $report['source'] ?? null,
            'counts' => $counts,
            'total' => is_array($counts) ? array_sum($counts) : null,
        ])->header('Cache-Control', 'no-store, private');
    }

    public function submitLabels(Request $request, string $dataset, CandleTraining $training): JsonResponse
    {
        $data = $request->validate([
            'delete_all' => ['sometimes', 'boolean'],
            'changes' => ['present', 'array', 'max:'.CandleTraining::submissionLimit()],
            'changes.*.decision_at_ms' => ['required', 'integer', 'min:1'],
            'changes.*.action' => ['nullable', Rule::in(CandleTraining::ACTIONS)],
        ]);
        $result = $training->submitLabels(
            $request->user(),
            $dataset,
            $data['changes'],
            (bool) ($data['delete_all'] ?? false),
        );

        return response()->json([...$result,
            'message' => 'Action Training labels submitted. The reviewed labels are now available to the next model build.',
        ])->header('Cache-Control', 'no-store, private');
    }

    public function update(Request $request, string $dataset, CandleTraining $training): RedirectResponse|JsonResponse
    {
        $data = $request->validate(['decision_at_ms' => ['required', 'integer', 'min:1'],
            'action' => ['required', Rule::in(CandleTraining::ACTIONS)]]);
        $label = $training->save($request->user(), $dataset, (int) $data['decision_at_ms'], $data['action']);

        if ($request->expectsJson()) {
            return response()->json([
                'decision_at_ms' => (int) $data['decision_at_ms'],
                'action' => $label->action,
                'message' => 'Candle marked '.strtoupper($label->action).'.',
            ]);
        }

        return redirect()->route('human-training.candles.show', [
            'dataset' => $dataset, 'decision_at_ms' => $data['decision_at_ms'],
        ])->with('status', 'Candle marked '.strtoupper($label->action).'.');
    }

    public function destroy(Request $request, string $dataset, CandleTraining $training): RedirectResponse|JsonResponse
    {
        $data = $request->validate(['decision_at_ms' => ['required', 'integer', 'min:1']]);
        $training->delete($request->user(), $dataset, (int) $data['decision_at_ms']);
        $message = 'Candle label removed. This candle is now unlabelled, not HOLD. Rebuild intelligence to remove the deleted label from any already-published model.';

        if ($request->expectsJson()) {
            return response()->json([
                'decision_at_ms' => (int) $data['decision_at_ms'],
                'deleted' => true,
                'message' => $message,
            ]);
        }

        return redirect()->route('human-training.candles.show', [
            'dataset' => $dataset, 'decision_at_ms' => $data['decision_at_ms'],
        ])->with('status', $message);
    }
}
