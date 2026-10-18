<?php

namespace App\Console\Commands;

use App\Jobs\TrainIntelligenceTournament;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

final class RunIntelligenceTournament extends Command
{
    protected $signature = 'trademinator:tournament {dataset? : Frozen research dataset UUID, omitted with --all}
        {--max-rows=1200 : Shared chronological sample cap between 100 and 4000}
        {--all : Queue each currently published market dataset}';
    protected $description = 'Queue research-only Rubix Outcome and Action tournaments on the dedicated tournament queue';

    public function handle(): int
    {
        if ($this->option('all') && $this->argument('dataset') !== null) {
            $this->error('Choose one dataset or --all, not both.');
            return self::FAILURE;
        }
        if (! $this->option('all') && $this->argument('dataset') === null) {
            $this->error('Provide a frozen dataset UUID or --all.');
            return self::FAILURE;
        }
        $limit = filter_var($this->option('max-rows'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 100 || $limit > 4000) {
            $this->error('--max-rows must be between 100 and 4000.');
            return self::FAILURE;
        }
        if (in_array(config('queue.default'), ['sync', 'null'], true)) {
            $this->error('Tournament requires an asynchronous queue connection.');
            return self::FAILURE;
        }

        $ids = $this->option('all')
            ? DB::table('intelligence_models as m')
                ->join('intelligence_heads as h', 'h.model_id', '=', 'm.model_id')
                ->select('m.dataset_id')->distinct()->orderBy('m.dataset_id')->cursor()
            : [(object) ['dataset_id' => $this->argument('dataset')]];

        $queued = 0;
        $failures = 0;
        foreach ($ids as $model) {
            try {
                if (! \Illuminate\Support\Str::isUuid((string) $model->dataset_id)) {
                    throw new InvalidArgumentException('Dataset ID must be a UUID.');
                }
                TrainIntelligenceTournament::dispatch((string) $model->dataset_id, $limit);
                $queued++;
            } catch (Throwable $error) {
                $failures++;
                $this->error($model->dataset_id.': '.$error->getMessage());
            }
        }
        $this->info("Tournament dispatch requests: {$queued}; errors: {$failures}. Unique jobs are deduplicated.");
        return $failures ? self::FAILURE : self::SUCCESS;
    }
}
