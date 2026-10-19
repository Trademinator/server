<?php

namespace App\Domain\Intelligence;

use App\Domain\Operations\ActionLog;
use App\Domain\Research\FeatureSchema;
use Composer\InstalledVersions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Rubix\ML\Classifiers\RandomForest;
use RuntimeException;
use Throwable;

final class ModelStore
{
    public static function marketKey(string $exchange, string $symbol, string $period): string
    {
        return hash('sha256', "$exchange|$symbol|$period");
    }

    public static function isReadyReport(?array $report): bool
    {
        return $report !== null && FeatureSchema::modelCompatible($report)
            && self::rubixCompatible($report)
            && ($report['status'] ?? null) === 'ready'
            && ($report['validation_version'] ?? null) === IntelligenceTrainer::VERSION
            && ($report['trained_as_of_ms'] ?? 0) >= KnowledgeWindow::fromMs(now()->getTimestampMs());
    }

    /** @return array<string, array{ready: bool, reason: string}> */
    public static function knnReadiness(?array $report): array
    {
        $unavailable = match (true) {
            $report === null => 'no_model',
            ! self::rubixCompatible($report) => 'model_version_mismatch',
            ($report['validation_version'] ?? null) !== IntelligenceTrainer::VERSION => 'model_version_mismatch',
            ! FeatureSchema::modelCompatible($report) => 'model_schema_outdated',
            ($report['trained_as_of_ms'] ?? 0) < KnowledgeWindow::fromMs(now()->getTimestampMs()) => 'stale_model',
            default => null,
        };

        $outcomeStatus = $report['outcome']['status'] ?? null;
        $outcomeReason = $report['outcome']['reason'] ?? 'outcome_model_unavailable';
        $actionStatus = $report['action']['status'] ?? null;
        $actionReason = $report['action']['reason'] ?? 'action_model_unavailable';
        $reasons = [
            'outcome' => $outcomeStatus === 'ready'
                ? 'validated' : ($outcomeReason === 'validated' ? 'outcome_model_unavailable' : $outcomeReason),
            'action' => $actionStatus === 'ready'
                ? 'validated' : ($actionReason === 'validated' ? 'action_model_unavailable' : $actionReason),
        ];
        $readiness = [];
        foreach ($reasons as $name => $reason) {
            $reason = $unavailable ?? $reason;
            $readiness[$name] = ['ready' => $reason === 'validated', 'reason' => $reason];
        }

        return $readiness;
    }

    public static function rubixCompatible(array $report): bool
    {
        return ($report['rubix_ml_major'] ?? null) === \Rubix\ML\VERSION;
    }

    private function requireCompatibleRubix(array $report, string $id): void
    {
        if (! self::rubixCompatible($report)) {
            throw new ModelRebuildRequired($id);
        }
    }

    public function path(string $id): string
    {
        if (! Str::isUuid($id)) {
            throw new InvalidArgumentException('Model ID must be a UUID.');
        }

        return rtrim(config('intelligence.path'), '/').'/'.$id.'.model';
    }

    public function knowledgePath(string $id): string
    {
        if (! Str::isUuid($id)) {
            throw new InvalidArgumentException('Model ID must be a UUID.');
        }

        return rtrim(config('intelligence.path'), '/').'/'.$id.'.knowledge.jsonl';
    }

    /** One sidecar per KNN source; do not allow arbitrary paths from model data. */
    public function humanKnowledgePath(string $id, string $source): string
    {
        if (! in_array($source, ['outcome', 'action'], true)) {
            throw new InvalidArgumentException('Unknown human knowledge source.');
        }

        $modelPath = $this->path($id);

        return substr($modelPath, 0, -strlen('.model')).'.'.$source.'.knowledge.jsonl';
    }

    public function coinGeckoPath(string $id): string
    {
        return substr($this->path($id), 0, -strlen('.model')).'.coingecko-rf';
    }

    public function coinGeckoEstimator(array $artifact): ?RandomForest
    {
        $bundle = $artifact['coingecko_insight'] ?? [];
        if (($bundle['status'] ?? null) !== 'validated') {
            return null;
        }
        $this->requireCompatibleRubix($artifact, (string) $artifact['model_id']);
        $digest = $bundle['estimator_sha256'] ?? null;
        $path = $this->coinGeckoPath((string) $artifact['model_id']);
        $bytes = is_file($path) ? file_get_contents($path) : false;
        if (! is_string($digest) || $bytes === false || ! hash_equals($digest, hash('sha256', $bytes))) {
            throw new RuntimeException('CoinGecko forest sidecar is missing or corrupt.');
        }
        $forest = unserialize($bytes);
        if (! $forest instanceof RandomForest) {
            throw new RuntimeException('Invalid CoinGecko forest artifact.');
        }

        return $forest;
    }

    public function save(array $artifact): array
    {
        if (! FeatureSchema::modelCompatible($artifact)) {
            throw new InvalidArgumentException('Cannot publish model with obsolete feature schema.');
        }
        $persistenceStarted = hrtime(true);
        $buildStarted = $artifact['build_performance']['started_monotonic_ns'] ?? null;
        unset($artifact['build_performance']['started_monotonic_ns']);
        $id = (string) Str::uuid7();
        $artifact['model_id'] = $id;
        $artifact['created_at'] = now()->toIso8601String();
        $artifact['format_version'] = 'm4-intelligence-v3';
        $artifact['rubix_ml_major'] = \Rubix\ML\VERSION;
        $artifact['rubix_ml_version'] = InstalledVersions::getPrettyVersion('rubix/ml');
        $path = $this->path($id);
        $knowledgePath = $this->knowledgePath($id);
        if (! is_dir(dirname($path)) && ! mkdir(dirname($path), 0700, true)) {
            throw new RuntimeException('Cannot create private model directory.');
        }

        $sidecars = [
            $knowledgePath,
            $this->humanKnowledgePath($id, 'outcome'),
            $this->humanKnowledgePath($id, 'action'),
            $this->coinGeckoPath($id),
        ];
        try {
            // Refuse a metadata-only artifact: quietly writing empty knowledge
            // files here would change trained predictions without retraining.
            if (! array_key_exists('knowledge', $artifact) && ($artifact['knowledge_rows'] ?? 0) > 0) {
                throw new RuntimeException('Automatic KNN knowledge must be supplied when saving a model.');
            }
            // Never serialize the training rows used during inference. A bounded
            // nearest-neighbor scan can stream all three verified JSONL sidecars.
            $artifact['knowledge_sha256'] = $this->publishRows($knowledgePath, $artifact['knowledge'] ?? []);
            unset($artifact['knowledge']);
            foreach (['outcome', 'action'] as $source) {
                $artifact[$source]['human'] ??= [];
                $bundle = &$artifact[$source]['human'];
                if (! array_key_exists('knowledge', $bundle) && ($bundle['knowledge_rows'] ?? 0) > 0) {
                    throw new RuntimeException('Human '.$source.' knowledge must be supplied when saving a model.');
                }
                $bundle['knowledge_sha256'] = $this->publishRows(
                    $this->humanKnowledgePath($id, $source), $bundle['knowledge'] ?? []
                );
                unset($bundle['knowledge'], $bundle['estimator']);
                unset($bundle);
            }
            // Persist the compact optional forest separately, keeping model metadata
            // and the report free of estimator objects during HTTP reads.
            if (($artifact['coingecko_insight']['estimator'] ?? null) instanceof RandomForest) {
                $forestFile = $this->coinGeckoPath($id);
                $forestBytes = serialize($artifact['coingecko_insight']['estimator']);
                if (file_put_contents($forestFile.'.tmp', $forestBytes, LOCK_EX) !== strlen($forestBytes)
                    || ! chmod($forestFile.'.tmp', 0600) || ! rename($forestFile.'.tmp', $forestFile)) {
                    throw new RuntimeException('Cannot publish CoinGecko forest.');
                }
                $artifact['coingecko_insight']['estimator_sha256'] = hash('sha256', $forestBytes);
            }
            unset($artifact['coingecko_insight']['estimator']);
            // These old aliases are retained as metadata, not as second copies
            // of potentially hundreds of thousands of human training rows.
            $artifact['human_guidance'] = $artifact['outcome']['human'];
            $artifact['candle_guidance'] = $artifact['action']['human'];
            $bytes = serialize($artifact);
            $report = $artifact;
            unset($report['patterns'], $report['lead_lag']['models'], $report['knowledge_sha256']);
            unset(
                $report['human_guidance']['estimator'], $report['candle_guidance']['estimator'],
                $report['outcome']['human']['estimator'], $report['action']['human']['estimator'],
            );
            $report['patterns'] = $artifact['patterns']['report'];
            if (file_put_contents($path.'.tmp', $bytes, LOCK_EX) !== strlen($bytes)
                || ! chmod($path.'.tmp', 0600) || ! rename($path.'.tmp', $path)) {
                throw new RuntimeException('Cannot publish intelligence model.');
            }
            DB::transaction(function () use ($id, $artifact, $bytes, $report): void {
                $key = self::marketKey($artifact['exchange'], $artifact['symbol'], $artifact['period']);
                DB::table('intelligence_models')->insert([
                    'model_id' => $id, 'dataset_id' => $artifact['dataset_id'], 'market_key' => $key,
                    'status' => $artifact['status'], 'generation_key' => $artifact['generation_key'] ?? null, 'sha256' => hash('sha256', $bytes),
                    'report' => json_encode($report, JSON_THROW_ON_ERROR), 'created_at' => now(),
                ]);
                $head = DB::table('intelligence_heads')->where('market_key', $key)->first();
                $previous = $head === null ? null : $this->report($head->model_id);
                if ($previous === null || $previous['trained_as_of_ms'] <= $artifact['trained_as_of_ms']) {
                    DB::table('intelligence_heads')->upsert([
                        'market_key' => $key, 'model_id' => $id, 'updated_at' => now(),
                    ], ['market_key'], ['model_id', 'updated_at']);
                }
            });
        } catch (Throwable $error) {
            foreach ([$path, ...$sidecars] as $file) {
                foreach ([$file.'.tmp', $file] as $candidate) {
                    if (is_file($candidate)) {
                        unlink($candidate);
                    }
                }
            }
            throw $error;
        }

        $persistenceMs = (int) round((hrtime(true) - $persistenceStarted) / 1_000_000);
        $report['build_performance']['stages']['persistence_ms'] = $persistenceMs;
        $report['build_performance']['total_ms'] = is_int($buildStarted)
            ? (int) round((hrtime(true) - $buildStarted) / 1_000_000)
            : array_sum($report['build_performance']['stages'] ?? []);
        DB::table('intelligence_models')->where('model_id', $id)->update([
            'report' => json_encode($report, JSON_THROW_ON_ERROR),
        ]);

        $performance = $report['build_performance'] ?? [];
        $stages = $performance['stages'] ?? [];
        app(ActionLog::class)->write('intelligence.build.completed', [
            'model_id' => $id, 'dataset_id' => $artifact['dataset_id'], 'exchange' => $artifact['exchange'],
            'symbol' => $artifact['symbol'], 'period' => $artifact['period'], 'status' => $artifact['status'],
            'reason' => $artifact['reason'] ?? null, 'knowledge_rows' => $artifact['knowledge_rows'] ?? 0,
            'total_ms' => $performance['total_ms'] ?? 0,
            'dataset_ms' => $stages['dataset_ms'] ?? 0, 'patterns_ms' => $stages['patterns_ms'] ?? 0,
            'lead_lag_ms' => $stages['lead_lag_ms'] ?? 0, 'knn_tuning_ms' => $stages['knn_tuning_ms'] ?? 0,
            'holdout_ms' => $stages['holdout_ms'] ?? 0, 'human_guidance_ms' => $stages['human_guidance_ms'] ?? 0,
            'candle_guidance_ms' => $stages['candle_guidance_ms'] ?? 0, 'persistence_ms' => $persistenceMs,
            'feature_replay_ms' => $performance['feature_replay']['duration_ms'] ?? 0,
            'feature_rows' => $performance['feature_replay']['rows_processed'] ?? 0,
            'feature_chunks' => $performance['feature_replay']['chunks'] ?? 0,
            'outcome' => 'completed',
        ]);

        return $report;
    }

    public function generation(string $key): ?array
    {
        $id = DB::table('intelligence_models')->where('generation_key', $key)->value('model_id');

        return $id === null ? null : $this->report($id);
    }

    public function report(string $id): array
    {
        $this->path($id);
        $record = DB::table('intelligence_models')->where('model_id', $id)->first();
        if ($record === null) {
            throw new InvalidArgumentException('Unknown intelligence model.');
        }

        return json_decode($record->report, true, flags: JSON_THROW_ON_ERROR);
    }

    public function load(string $id): array
    {
        $artifact = $this->loadArtifact($id);
        if (in_array($artifact['format_version'] ?? null, ['m4-intelligence-v2', 'm4-intelligence-v3'], true)) {
            $artifact['knowledge'] = iterator_to_array($this->knowledge($artifact), false);
        }
        if (($artifact['format_version'] ?? null) === 'm4-intelligence-v3') {
            foreach (['outcome', 'action'] as $source) {
                $artifact[$source]['human']['knowledge'] = iterator_to_array(
                    $this->humanKnowledge($artifact, $source), false
                );
            }
            // Keep full artifact loading compatible with older admin/test callers;
            // inference and model-info deliberately never materialize these rows.
            $artifact['human_guidance'] = $artifact['outcome']['human'];
            $artifact['candle_guidance'] = $artifact['action']['human'];
        }

        return $artifact;
    }

    public function current(string $exchange, string $symbol, string $period): ?array
    {
        $id = DB::table('intelligence_heads')->where('market_key', self::marketKey($exchange, $symbol, $period))->value('model_id');

        return $id === null ? null : $this->load($id);
    }

    /** Load a pinned model while keeping KNN knowledge in streamed sidecars. */
    public function forPrediction(string $id): array
    {
        return $this->loadArtifact($id);
    }

    public function currentForPrediction(string $exchange, string $symbol, string $period): ?array
    {
        $id = DB::table('intelligence_heads')->where('market_key', self::marketKey($exchange, $symbol, $period))->value('model_id');

        return $id === null ? null : $this->loadArtifact($id);
    }

    /**
     * Read the published model report without deserializing the model artifact.
     * Safe for memory-bounded HTTP requests; inference remains in queue workers.
     */
    public function currentReport(string $exchange, string $symbol, string $period): ?array
    {
        $json = DB::table('intelligence_heads as heads')
            ->join('intelligence_models as models', 'models.model_id', '=', 'heads.model_id')
            ->where('heads.market_key', self::marketKey($exchange, $symbol, $period))
            ->value('models.report');

        return $json === null ? null : json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }

    public function knowledge(array $artifact): iterable
    {
        if (($artifact['format_version'] ?? null) === 'm4-intelligence-v1') {
            yield from $artifact['knowledge'] ?? [];

            return;
        }
        if (! in_array($artifact['format_version'] ?? null, ['m4-intelligence-v2', 'm4-intelligence-v3'], true)) {
            throw new RuntimeException('Unsupported intelligence artifact.');
        }

        yield from $this->readRows($this->knowledgePath($artifact['model_id']),
            (string) ($artifact['knowledge_sha256'] ?? ''), 'Intelligence model knowledge');
    }

    /** Human rows are inline only in legacy v1/v2 models. */
    public function humanKnowledge(array $artifact, string $source): iterable
    {
        if (! in_array($source, ['outcome', 'action'], true)) {
            throw new InvalidArgumentException('Unknown human knowledge source.');
        }
        if (($artifact['format_version'] ?? null) === 'm4-intelligence-v3') {
            yield from $this->readRows($this->humanKnowledgePath($artifact['model_id'], $source),
                (string) ($artifact[$source]['human']['knowledge_sha256'] ?? ''), 'Human '.$source.' knowledge');

            return;
        }
        if (! in_array($artifact['format_version'] ?? null, ['m4-intelligence-v1', 'm4-intelligence-v2'], true)) {
            throw new RuntimeException('Unsupported intelligence artifact.');
        }

        yield from $artifact[$source]['human']['knowledge'] ?? [];
    }

    /** Verify all three knowledge digests without growing the PHP heap with rows. */
    public function verify(string $id): void
    {
        $artifact = $this->loadArtifact($id);
        foreach ($this->knowledge($artifact) as $_) {
            // Checksums are finalized only after the full stream is consumed.
        }
        foreach (['outcome', 'action'] as $source) {
            foreach ($this->humanKnowledge($artifact, $source) as $_) {
            }
        }
        $this->coinGeckoEstimator($artifact);
    }

    private function publishRows(string $path, iterable $rows): string
    {
        $handle = fopen($path.'.tmp', 'wb');
        if ($handle === false) {
            throw new RuntimeException('Cannot create private model knowledge file.');
        }
        $hash = hash_init('sha256');
        try {
            foreach ($rows as $row) {
                $line = json_encode($row, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)."\n";
                if (fwrite($handle, $line) !== strlen($line)) {
                    throw new RuntimeException('Cannot write private model knowledge file.');
                }
                hash_update($hash, $line);
            }
        } finally {
            fclose($handle);
        }
        if (! chmod($path.'.tmp', 0600) || ! rename($path.'.tmp', $path)) {
            throw new RuntimeException('Cannot publish private model knowledge file.');
        }

        return hash_final($hash);
    }

    private function readRows(string $path, string $expectedHash, string $description): iterable
    {
        $handle = is_file($path) ? fopen($path, 'rb') : false;
        if ($handle === false) {
            throw new RuntimeException($description.' is missing.');
        }

        $hash = hash_init('sha256');
        try {
            while (($line = fgets($handle)) !== false) {
                hash_update($hash, $line);
                yield json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            }
        } finally {
            fclose($handle);
        }
        if (! hash_equals($expectedHash, hash_final($hash))) {
            throw new RuntimeException($description.' checksum does not match.');
        }
    }

    private function loadArtifact(string $id): array
    {
        $path = $this->path($id);
        $record = DB::table('intelligence_models')->where('model_id', $id)->first();
        $bytes = is_file($path) ? file_get_contents($path) : false;
        if ($record === null || $bytes === false || ! hash_equals($record->sha256, hash('sha256', $bytes))) {
            throw new RuntimeException('Intelligence model is missing or its checksum does not match.');
        }
        // Check independently stored JSON before any old Rubix __unserialize() can run.
        $this->requireCompatibleRubix(json_decode($record->report, true, flags: JSON_THROW_ON_ERROR), $id);
        // Only our private artifacts, authenticated by the independently stored DB digest, are decoded.
        // Never accept arbitrary uploaded or user-supplied serialized models.
        $artifact = unserialize($bytes);
        if (! is_array($artifact) || ! in_array($artifact['format_version'] ?? null, ['m4-intelligence-v1', 'm4-intelligence-v2', 'm4-intelligence-v3'], true)
            || ($artifact['model_id'] ?? null) !== $id) {
            throw new RuntimeException('Unsupported intelligence artifact.');
        }
        $this->requireCompatibleRubix($artifact, $id);

        return $artifact;
    }
}
