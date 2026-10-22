<?php

namespace App\Domain\Intelligence;

use App\Domain\Features\FeatureEngine;
use App\Domain\Research\FeatureSchema;
use App\Domain\Research\DatasetStore;

/**
 * A candidate is immutable even when not promoted. Compare only equivalent,
 * separately held-out evaluations; an incomparable result cannot displace
 * a functional incumbent.
 */
final class ModelPromotionPolicy
{
    public static function mayPromote(array $candidate, ?array $incumbent): bool
    {
        if (($candidate['status'] ?? null) !== 'ready'
            || ! ModelStore::rubixCompatible($candidate)
            || ($candidate['validation_version'] ?? null) !== IntelligenceTrainer::VERSION
            || ! FeatureSchema::modelCompatible($candidate)
            || ($candidate['feature_version'] ?? null) !== FeatureEngine::VERSION
            || ! self::passesRequiredGates($candidate)) {
            return false;
        }

        try {
            $manifest = app(DatasetStore::class)->manifest((string) $candidate['dataset_id']);
        } catch (\Throwable) {
            return false;
        }
        if (($manifest['rows_sha256'] ?? null) !== ($candidate['source_rows_sha256'] ?? null)
            || ($manifest['feature_version'] ?? null) !== ($candidate['feature_version'] ?? null)
            || $manifest['keys'] !== ($candidate['keys'] ?? null)
            || [$manifest['exchange'], $manifest['symbol'], $manifest['period']]
                !== [$candidate['exchange'], $candidate['symbol'], $candidate['period']]) {
            return false;
        }

        if ($incumbent === null || ! ModelStore::isReadyReport($incumbent)) {
            return true;
        }

        // A lossless migration of the current active model is not a new
        // training competitor, and may retain the existing active position.
        if (($candidate['compacted_from_model_id'] ?? null) === ($incumbent['model_id'] ?? null)
            && ($candidate['trained_as_of_ms'] ?? null) === ($incumbent['trained_as_of_ms'] ?? null)
            && ($candidate['source_rows_sha256'] ?? null) === ($incumbent['source_rows_sha256'] ?? null)
            && ($candidate['dataset_id'] ?? null) === ($incumbent['dataset_id'] ?? null)
            && ($candidate['feature_version'] ?? null) === ($incumbent['feature_version'] ?? null)
            && ($candidate['keys'] ?? null) === ($incumbent['keys'] ?? null)
            && ($candidate['validation_version'] ?? null) === ($incumbent['validation_version'] ?? null)
            && ($candidate['outcome']['status'] ?? null) === ($incumbent['outcome']['status'] ?? null)
            && ($candidate['action']['status'] ?? null) === ($incumbent['action']['status'] ?? null)) {
            return true;
        }

        if (($candidate['trained_as_of_ms'] ?? 0) <= ($incumbent['trained_as_of_ms'] ?? 0)) {
            return false;
        }

        // Comparable evaluation requires the exact same chronological holdout.
        foreach (['outcome', 'action'] as $name) {
            if (($candidate[$name]['holdout_from_ms'] ?? $candidate['holdout_from_ms'] ?? null)
                !== ($incumbent[$name]['holdout_from_ms'] ?? $incumbent['holdout_from_ms'] ?? null)) {
                return false;
            }
            $new = $candidate[$name]['algorithmic']['holdout'] ?? null;
            $old = $incumbent[$name]['algorithmic']['holdout'] ?? null;
            if (! is_array($new) || ! is_array($old)
                || ($new['evaluated'] ?? null) !== ($old['evaluated'] ?? null)) {
                return false;
            }
        }

        $newAction = $candidate['action']['algorithmic']['holdout']['semantic_precision'] ?? null;
        $oldAction = $incumbent['action']['algorithmic']['holdout']['semantic_precision'] ?? null;
        $newOutcome = $candidate['outcome']['algorithmic']['holdout']['supported_macro_f1'] ?? null;
        $oldOutcome = $incumbent['outcome']['algorithmic']['holdout']['supported_macro_f1'] ?? null;
        if (! is_numeric($newAction) || ! is_numeric($oldAction)
            || ! is_numeric($newOutcome) || ! is_numeric($oldOutcome)) {
            return false;
        }

        // A strict Pareto improvement avoids trading lower quality in one
        // mandatory path for a gain in the other without a calibrated utility.
        return $newAction >= $oldAction && $newOutcome >= $oldOutcome
            && ($newAction > $oldAction || $newOutcome > $oldOutcome);
    }

    private static function passesRequiredGates(array $candidate): bool
    {
        // Production readiness already includes independently validated human
        // fallback pathways. Keep the same support policy, not KNN-only gates.
        return ($candidate['outcome']['status'] ?? null) === 'ready'
            && ($candidate['action']['status'] ?? null) === 'ready';
    }
}
