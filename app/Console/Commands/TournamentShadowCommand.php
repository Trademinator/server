<?php

namespace App\Console\Commands;

use App\Domain\Intelligence\TournamentShadow;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Jobs\RunTournamentShadow;
use Throwable;

final class TournamentShadowCommand extends Command
{
    protected $signature = 'trademinator:shadow {mode : publish|predict|score} {--dataset= : Tournament dataset UUID}';
    protected $description = 'Research-only versioned challenger publishing, candle prediction and delayed scoring';

    public function handle(TournamentShadow $shadow): int
    {
        try {
            $mode=(string)$this->argument('mode');
            if ($mode==='publish') {
                $ids=$this->option('dataset')
                    ? [(object)['dataset_id'=>$this->option('dataset')]]
                    : DB::table('intelligence_tournaments')->select('dataset_id')->cursor();
                $failed = 0;
                foreach ($ids as $record) {
                    try {
                        $result=$shadow->publish((string)$record->dataset_id);
                        if ($result !== []) $this->line($record->dataset_id.': '.count($result).' published');
                    } catch (Throwable $error) {
                        $failed++;
                        $this->warn($record->dataset_id.': '.$error->getMessage());
                    }
                }
                if ($failed && $this->option('dataset')) return self::FAILURE;
            } elseif ($mode==='predict' || $mode==='score') {
                if (in_array(config('queue.default'), ['sync', 'null'], true)) {
                    $this->error('Shadow inference requires a persistent queue.');
                    return self::FAILURE;
                }
                foreach (DB::table('tournament_shadow_models')->select('shadow_model_id')->cursor() as $model) {
                    RunTournamentShadow::dispatch($model->shadow_model_id, $mode);
                }
            } else {
                $this->error('Mode must be publish, predict, or score.');
                return self::FAILURE;
            }
            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }
    }
}
