<?php

namespace App\View\Components;

use App\Domain\Intelligence\ModelStore;
use App\Helpers\Decimal;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class KnnReadiness extends Component
{
    public array $indicators = [];

    public function __construct(?array $report = null, public ?array $counts = null, public int $total = 0, ?array $coingecko = null)
    {
        $states = ModelStore::knnReadiness($report);
        $states['coingecko'] = $coingecko ?? ['ready' => false, 'reason' => 'coingecko_features_unavailable'];
        foreach (['outcome' => 'Outcome KNN', 'action' => 'Action KNN', 'coingecko' => 'CoinGecko context'] as $name => $label) {
            $ready = $counts !== null || $states[$name]['ready'];
            $humanFallback = $counts === null && $ready && in_array($name, ['outcome', 'action'], true)
                && ! in_array($report[$name]['algorithmic']['status'] ?? null, ['ready', 'validated'], true)
                && ($report[$name]['human']['status'] ?? null) === 'validated';
            $description = $counts !== null
                ? $label.': '.Decimal::format($counts[$name] ?? 0).' of '.Decimal::format($total).' ready'
                : $label.': '.($ready
                    ? ($humanFallback ? 'Ready via human-trained fallback' : 'Ready')
                    : 'Not ready — '.$this->explanation($states[$name]['reason']));
            $this->indicators[$name] = ['ready' => $ready, 'description' => $description,
                'label' => (match ($name) {
                    'outcome' => 'Outcome', 'action' => 'Action', default => 'CoinGecko'
                }).($humanFallback ? ' (human)' : ''),
                'count' => $counts[$name] ?? 0];
        }
    }

    /**
     * Get the view / contents that represent the component.
     */
    public function render(): View
    {
        return view('components.knn-readiness');
    }

    private function explanation(string $reason): string
    {
        return match ($reason) {
            'no_model' => 'No model built yet',
            'model_version_mismatch', 'candle_model_version_mismatch' => 'Rebuild required for the current model version',
            'stale_model' => 'Model expired; rebuild required',
            'zero_scoring_weight' => 'Scoring weight is zero',
            'candle_training_disabled', 'disabled' => 'Human Action Training is disabled',
            'insufficient_candle_labels' => 'More eligible candle labels are needed',
            'insufficient_action_diversity' => 'More than one action class is needed',
            'no_eligible_k', 'tuning_failed' => 'Tuning validation has not passed',
            'holdout_failed' => 'Holdout validation has not passed',
            'insufficient_directional_evidence' => 'Not enough directional evidence to validate; this is not a failed quality test',
            'optional_budget_exhausted' => 'Human training exceeded its build time budget',
            'coingecko_disabled' => 'CoinGecko collection is disabled',
            'coingecko_period_pending' => 'Waiting for a selected candle period',
            'coingecko_mapping_unresolved' => 'CoinGecko asset mapping is unresolved',
            'coingecko_quote_mismatch' => 'CoinGecko mapping must match the market quote currency',
            'coingecko_features_unavailable' => 'Waiting for current candle context features',
            'coingecko_features_stale' => 'Latest candle context features are stale',
            'coingecko_context_incomplete' => 'Some essential CoinGecko context fields are missing',
            'coingecko_context_invalid' => 'CoinGecko context needs rebuilding',
            'coingecko_snapshot_unavailable' => 'The matching CoinGecko source snapshot is unavailable',
            'coingecko_context_stale' => 'CoinGecko source data has expired',
            default => ucfirst(str_replace('_', ' ', $reason)),
        };
    }
}
