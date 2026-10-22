<?php

namespace App\Console\Commands;

use App\Domain\Intelligence\ModelStore;
use Illuminate\Console\Command;
use Throwable;

final class CompactIntelligenceModel extends Command
{
    protected $signature = 'trademinator:compact-model {exchange} {symbol} {period}';

    protected $description = 'Convert the active intelligence model to streamed, low-memory knowledge without retraining';

    public function handle(ModelStore $models): int
    {
        try {
            $exchange = (string) $this->argument('exchange');
            $symbol = (string) $this->argument('symbol');
            $period = (string) $this->argument('period');
            $artifact = $models->currentForPrediction($exchange, $symbol, $period);
            if ($artifact === null) {
                $this->error('No active intelligence model for this market and period.');

                return self::FAILURE;
            }
            if ($artifact['format_version'] === 'm4-intelligence-v3') {
                $this->info('Model '.$artifact['model_id'].' is already compact.');

                return self::SUCCESS;
            }

            if ($artifact['format_version'] !== 'm4-intelligence-v2') {
                $this->error('Only v2 models can be compacted safely; rebuild older models.');

                return self::FAILURE;
            }
            $previousId = $artifact['model_id'];
            // Do not materialize the possibly massive automatic knowledge file.
            $artifact['knowledge'] = $models->knowledge($artifact);
            // Generation keys identify training runs and must remain unique.
            // Publish a new immutable artifact; keep the old model as a rollback.
            $artifact['generation_key'] = null;
            $artifact['compacted_from_model_id'] = $previousId;
            $report = $models->save($artifact);
            $active = $models->currentForPrediction($exchange, $symbol, $period);
            if ($active['model_id'] !== $report['model_id']) {
                $this->error('A newer model became active during compaction; the old head was not overwritten.');

                return self::FAILURE;
            }
            $this->info('Compacted model '.$previousId.' into '.$report['model_id'].' (no retraining).');

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }
}
