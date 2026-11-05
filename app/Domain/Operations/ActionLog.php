<?php

namespace App\Domain\Operations;

use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Throwable;

class ActionLog
{
    public function __construct(private LoggerInterface $logger, private ActionContext $context) {}

    /** Only identifiers, fixed operation labels, public market names and numeric metrics are accepted. */
    public function write(string $event, array $fields = []): void
    {
        if (! preg_match('/^[a-z][a-z0-9_.]{0,79}$/D', $event)) {
            return;
        }
        $frame = $this->context->current();
        $record = ['time' => gmdate('Y-m-d\TH:i:s\Z'), 'event' => $event,
            'trace_id' => $frame['trace_id'] ?? (string) Str::uuid()];
        $fields = [...$frame, ...$fields];
        foreach (['parent_trace_id', 'actor_id', 'subject_id', 'market_id', 'subscription_id', 'model_id', 'dataset_id', 'job_id'] as $key) {
            if (is_string($fields[$key] ?? null) && Str::isUuid($fields[$key])) {
                $record[$key] = $fields[$key];
            }
        }
        foreach (['route', 'method', 'command', 'job', 'queue', 'outcome', 'error', 'source', 'reason', 'exchange', 'period', 'action', 'status', 'stage'] as $key) {
            $value = $fields[$key] ?? null;
            if (is_string($value) && preg_match('/^[a-zA-Z0-9_\\\\.\/:{}-]{1,160}$/D', $value)) {
                $record[$key] = $value;
            }
        }
        if (is_string($fields['symbol'] ?? null) && preg_match('/^[A-Z0-9_.:\/-]{1,32}$/D', $fields['symbol'])) {
            $record['symbol'] = $fields['symbol'];
        }
        foreach ([
            'status_code', 'exit_code', 'duration_ms', 'attempt', 'line', 'rows', 'fetched', 'repaired',
            'missing_ranges', 'knowledge_rows', 'confidence', 'effective_neighbors', 'candle_ms',
            'total_ms', 'dataset_ms', 'patterns_ms', 'lead_lag_ms', 'knn_tuning_ms', 'holdout_ms',
            'human_guidance_ms', 'candle_guidance_ms', 'persistence_ms',
            'feature_replay_ms', 'feature_rows', 'feature_chunks',
            'budget_seconds', 'remaining_ms', 'processed', 'total', 'eligible_rows', 'memory_bytes',
        ] as $key) {
            if (is_int($fields[$key] ?? null) || (is_float($fields[$key] ?? null) && is_finite($fields[$key]))) {
                $record[$key] = $fields[$key];
            }
        }
        $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        try {
            $this->logger->log(in_array($record['outcome'] ?? '', ['failed', 'error', 'timeout'], true) ? 'error' : 'info', $line);
        } catch (Throwable) {
            // Do not let a failed logging transport roll back a successful operation, or leak its exception.
            error_log('trademinator syslog_transport_failed '.$line);
        }
    }

    public function exception(Throwable $error): array
    {
        $source = $error->getFile();
        $line = $error->getLine();
        foreach ($error->getTrace() as $frame) {
            if (str_starts_with($frame['file'] ?? '', app_path().DIRECTORY_SEPARATOR)) {
                $source = $frame['file'];
                $line = $frame['line'] ?? 0;
                break;
            }
        }

        return ['error' => get_class($error), 'source' => basename($source), 'line' => $line];
    }
}
