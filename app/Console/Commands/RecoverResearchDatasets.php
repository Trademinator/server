<?php

namespace App\Console\Commands;

use App\Domain\Research\DatasetStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class RecoverResearchDatasets extends Command
{
    protected $signature = 'trademinator:recover-research-datasets
        {--apply : Restore missing/mismatched manifest.json files only when rows.jsonl still matches the DB manifest}
        {--human-only : Inspect only datasets referenced by immutable human-training snapshots}';

    protected $description = 'Audit missing research artifacts and safely restore recoverable dataset manifests';

    public function handle(DatasetStore $datasets): int
    {
        try {
            $humanIds = DB::table('human_training_snapshots')->distinct()->pluck('dataset_id')
                ->map(static fn ($id): string => (string) $id)->all();
            $human = array_fill_keys($humanIds, true);
            $query = DB::table('research_datasets')->select('dataset_id')->orderBy('created_at')->orderBy('dataset_id');
            if ($this->option('human-only')) {
                if ($humanIds === []) {
                    $this->info('No human-training datasets are recorded.');

                    return self::SUCCESS;
                }
                $query->whereIn('dataset_id', $humanIds);
            }

            $statuses = ['present', 'rows_missing', 'rows_invalid', 'manifest_missing', 'manifest_mismatch'];
            $counts = array_fill_keys($statuses, 0);
            $humanCounts = array_fill_keys($statuses, 0);
            $repaired = 0;
            $inspected = 0;
            foreach ($query->cursor() as $record) {
                $id = (string) $record->dataset_id;
                $inspection = $datasets->inspectArtifact($id);
                $status = $inspection['status'];
                if (! array_key_exists($status, $counts)) {
                    throw new \RuntimeException('Unknown dataset artifact status: '.$status);
                }
                $inspected++;
                $counts[$status]++;
                if (isset($human[$id])) {
                    $humanCounts[$status]++;
                }

                if ($this->option('apply') && $inspection['repairable']) {
                    if (! $datasets->repairManifest($id)) {
                        throw new \RuntimeException('Dataset changed while its manifest was being repaired: '.$id);
                    }
                    $repaired++;
                }
            }

            $this->table(
                ['Artifact status', 'Inspected', 'Human referenced'],
                array_map(static fn (string $status): array => [
                    $status, $counts[$status], $humanCounts[$status],
                ], $statuses)
            );
            $this->line('Datasets inspected: '.$inspected);
            $this->line('Human-training dataset IDs: '.count($humanIds));
            if ($this->option('apply')) {
                $this->info('Repaired manifests: '.$repaired);
            } else {
                $this->info('Dry run only. Use --apply to restore only manifests backed by checksum-valid rows.');
            }
            if ($counts['rows_missing'] + $counts['rows_invalid'] > 0) {
                $this->warn('Missing or invalid rows.jsonl files are not recreated or deleted by this command.');
            }

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }
}
