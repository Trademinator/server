<?php

namespace App\Domain\Intelligence;

use App\Models\Exchange;
use App\Models\MarketFeed;
use Illuminate\Support\Facades\DB;

final class LeadLagIntelligence
{
    public function __construct(private LeadLagSeries $series, private LeadLagTrainer $trainer, private ExchangeTimezone $timezones) {}

    public function prepare(array $manifest, array|TrainingRows $rows, float $deadline): array
    {
        if (DB::transactionLevel() === 0 && in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }

        return DB::transaction(fn (): array => $this->snapshot($manifest, $rows, $deadline));
    }

    private function snapshot(array $manifest, array|TrainingRows $rows, float $deadline): array
    {
        $settings = config('lead_lag');
        $bundle = ['version' => LeadLagTrainer::VERSION, 'models' => [], 'report' => [], 'settings' => $settings,
            'status' => 'disabled', 'keys' => [], 'available_at_ms' => 0];
        $series = [];
        if (! $settings['enabled']) {
            return compact('bundle', 'series');
        }
        $step = LeadLagSeries::step($manifest['period']);
        if ($step === null || str_contains($manifest['symbol'], ':')) {
            $bundle['status'] = 'unsupported_period_or_instrument';

            return compact('bundle', 'series');
        }
        if (count($rows) < 3) {
            $bundle['status'] = 'insufficient_history';

            return compact('bundle', 'series');
        }
        // All auxiliary model selection/evaluation ends before downstream KNN decisions.
        $cutoff = $rows[(int) floor(count($rows) * 0.4)]['decision_at_ms'] - 1;
        $from = max(KnowledgeWindow::fromMs($manifest['as_of_ms']), $cutoff - $settings['max_bars'] * $step);
        $follower = $this->series->load($manifest['exchange'], $manifest['symbol'], $manifest['period'], $from, $cutoff);
        $peers = MarketFeed::query()->with('market.exchange')->whereNotNull('selected_period')
            ->whereHas('market', fn ($q) => $q->where('symbol', $manifest['symbol'])
                ->whereHas('exchange', fn ($e) => $e->where('class', '!=', $manifest['exchange']))
                ->whereHas('subscriptions', fn ($s) => $s->where('active', true)))
            ->get()->sortBy(fn ($feed) => $feed->market->exchange->class)->unique(fn ($feed) => $feed->market->exchange->class);
        $bundle['status'] = 'no_matching_peer';
        $bundle['peers_omitted_by_cap'] = max(0, $peers->count() - $settings['max_peers']);
        $bundle['follower'] = ['exchange' => $manifest['exchange'], 'symbol' => $manifest['symbol'], 'period' => $manifest['period']];
        $followerExchange = Exchange::query()->where('class', $manifest['exchange'])->first();
        $bundle['follower_context'] = $followerExchange === null ? null : $this->timezones->context($followerExchange);
        foreach ($peers->take($settings['max_peers']) as $feed) {
            $exchange = $feed->market->exchange;
            if ($feed->selected_period !== $manifest['period']) {
                $bundle['report'][$exchange->class] = ['status' => 'different_selected_period', 'period' => $feed->selected_period,
                    'samples' => 0, 'minimum_samples' => $settings['min_samples']];

                continue;
            }
            $context = $this->timezones->context($exchange);
            $leader = $this->series->load($exchange->class, $manifest['symbol'], $manifest['period'], $from, $cutoff);
            $trained = $this->trainer->train($leader, $follower, $step, $context, $settings, $deadline);
            $trained['report']['source_sha256'] = hash('sha256', json_encode([$leader, $follower], JSON_THROW_ON_ERROR));
            $trained['report']['as_of_ms'] = $cutoff;
            $bundle['report'][$exchange->class] = $trained['report'];
            if ($trained['model'] !== null) {
                $model = $trained['model'] + ['exchange' => $exchange->class, 'symbol' => $manifest['symbol'], 'period' => $manifest['period']];
                $bundle['models'][$exchange->class] = $model;
                $bundle['keys'][] = 'lead_lag.'.$exchange->class.'.direction';
                $bundle['available_at_ms'] = max($bundle['available_at_ms'], $model['available_at_ms']);
                $series[$exchange->class] = $this->series->load($exchange->class, $manifest['symbol'], $manifest['period'],
                    $model['available_at_ms'], $manifest['as_of_ms']);
            }
        }
        if ($bundle['report'] !== []) {
            $bundle['status'] = $bundle['models'] === [] ? 'no_validated_relationship' : 'validated';
        }

        return compact('bundle', 'series');
    }

    public function features(array $bundle, array $series, int $atMs): array
    {
        $vector = $signals = $weights = [];
        foreach ($bundle['models'] as $exchange => $model) {
            $bar = $series[$exchange][$atMs] ?? null;
            $session = ExchangeTimezone::session($atMs, $model['context']['timezone']);
            $reason = match (true) {
                ! config('lead_lag.enabled') => 'disabled',
                $atMs <= $model['available_at_ms'] => 'not_yet_validated',
                $atMs > $model['available_at_ms'] + $bundle['settings']['max_age_days'] * 86400000 => 'stale_evidence',
                $model['session'] !== 'all' && $session !== $model['session'] => 'outside_validated_session',
                $bar === null => 'missing_closed_leader_candle',
                default => 'validated',
            };
            $contribution = $reason === 'validated' ? $model['fit']['leader_beta'] * $bar['return'] : 0.0;
            $influence = $reason === 'validated' ? min($bundle['settings']['max_influence'], $model['strength']) : 0.0;
            $score = $influence * max(-1, min(1, $contribution / (3 * $model['fit']['scale'])));
            $vector[] = 0.5 + 0.5 * $score;
            $weights[] = $influence;
            $signals[$exchange] = ['reason' => $reason, 'score' => $score, 'influence' => $influence,
                'lag_candles' => $model['lag'], 'lag_ms' => $model['lag'] * LeadLagSeries::step($model['period']),
                'leader_at_ms' => $bar === null ? null : $atMs, 'session' => $session];
        }

        return compact('vector', 'signals', 'weights');
    }

    public function current(array $bundle, int $atMs): array
    {
        $series = [];
        foreach ($bundle['models'] as $exchange => $model) {
            $series[$exchange] = $this->series->load($exchange, $model['symbol'], $model['period'], $atMs, $atMs);
            $configured = Exchange::query()->where('class', $exchange)->first();
            if ($configured === null || $this->timezones->context($configured) !== $model['context']) {
                // A timezone edit changes the learned session definition; wait for a fresh model.
                $series[$exchange] = [];
            }
        }

        return $this->features($bundle, $series, $atMs);
    }
}
