<?php

namespace App\Http\Controllers\Owner;

use App\Domain\Operations\ActionLog;
use App\Domain\Operations\QueueAdministration;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class QueueJobController extends Controller
{
    public function index(Request $request, QueueAdministration $queues): View
    {
        $selection = $this->selection($request);
        $filters = $request->validate([
            'state' => ['nullable', Rule::in(['waiting', 'delayed', 'reserved'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $state = $filters['state'] ?? 'waiting';
        $error = null;
        try {
            $snapshot = $queues->snapshot($selection['connection'], $selection['queue'], $state, (int) ($filters['page'] ?? 1));
        } catch (Throwable $exception) {
            report($exception);
            $snapshot = ['jobs' => [], 'total' => 0, 'page' => 1, 'per_page' => 50];
            $error = 'This queue could not be read. Its contents have not been changed. Please refresh after the connection is restored.';
        }

        return view('owner.queue-jobs', compact('selection', 'state', 'snapshot', 'error'));
    }

    public function show(Request $request, string $job, QueueAdministration $queues): View
    {
        $selection = $this->selection($request);
        $detail = $queues->inspect($selection['connection'], $selection['queue'], $job);
        abort_if($detail === null, 404, 'This job is no longer in the queue. It may have finished or been cancelled.');

        return view('owner.queue-job', compact('selection', 'detail'));
    }

    public function move(Request $request, string $job, QueueAdministration $queues, ActionLog $log): RedirectResponse
    {
        $selection = $this->selection($request);
        $direction = $request->validate(['direction' => ['required', Rule::in(['up', 'down', 'top', 'bottom', 'automatic'])]])['direction'];
        abort_unless($queues->move($selection['connection'], $selection['queue'], $job, $direction), 409,
            'This job could not be moved. It may have been claimed or its queue order changed. Refresh the queue before trying again.');
        $log->write('owner.queue_job.move', ['job_id' => $job, 'queue' => $selection['queue'], 'action' => $direction, 'outcome' => 'completed']);

        return to_route('owner.queue-jobs.index', $selection)->with('status', $direction === 'automatic'
            ? 'Automatic priority restored for job '.$job.'.'
            : 'Queue order updated for job '.$job.'.');
    }

    public function destroy(Request $request, string $job, QueueAdministration $queues, ActionLog $log): RedirectResponse
    {
        $selection = $this->selection($request);
        abort_unless($queues->cancel($selection['connection'], $selection['queue'], $job), 409,
            'This job could not be cancelled. It may already be claimed, or its cancellation needs additional cleanup. Refresh its details.');
        $log->write('owner.queue_job.cancel', ['job_id' => $job, 'queue' => $selection['queue'], 'outcome' => 'completed']);

        return to_route('owner.queue-jobs.index', $selection)->with('status', 'Pending job '.$job.' cancelled.');
    }

    /** @return array{connection: string, queue: string} */
    private function selection(Request $request): array
    {
        $connections = array_keys(array_filter((array) config('queue.connections'),
            fn (array $connection): bool => ($connection['driver'] ?? null) === 'redis'));

        return $request->validate([
            'connection' => ['required', 'string', Rule::in($connections)],
            'queue' => ['required', 'string', 'max:160', 'regex:/\A[A-Za-z0-9_.:{}-]+\z/D'],
        ]);
    }
}
