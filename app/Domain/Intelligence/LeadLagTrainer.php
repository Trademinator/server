<?php

namespace App\Domain\Intelligence;

use RuntimeException;

/** Select on an earlier block; test one frozen lag/session on untouched later returns. */
final class LeadLagTrainer
{
    public const VERSION = 'lead-lag-v1';

    public function train(array $leader, array $follower, int $step, array $context, array $settings, float $deadline): array
    {
        $sessions = $context['timezone_source'] === 'unknown' ? ['all'] : ['all', '00-06', '06-12', '12-18', '18-24'];
        $maximumLag = (int) $settings['max_lag'];
        $commonDeadline = TrainingRuntime::algorithmDeadline($deadline);
        $common = $this->observations($leader, $follower, $step, $maximumLag, $context['timezone'], $commonDeadline);
        $commonCount = count($common);
        $selectionAt = $common[(int) floor($commonCount * 0.6)]['at'] ?? PHP_INT_MAX;
        $testAt = $common[(int) floor($commonCount * 0.8)]['at'] ?? PHP_INT_MAX;
        $candidates = [];
        $largest = 0;
        $blocksReady = false;
        for ($lag = 1; $lag <= $maximumLag; $lag++) {
            $observations = $lag === $maximumLag ? $common : $this->observations(
                $leader, $follower, $step, $lag, $context['timezone'], TrainingRuntime::algorithmDeadline($deadline)
            );
            $largest = max($largest, count($observations));
            $count = count($observations);
            if ($count < $settings['min_samples']) {
                continue;
            }
            foreach ($sessions as $session) {
                $runDeadline = TrainingRuntime::algorithmDeadline($deadline);
                $this->assertWithinDeadline($runDeadline);
                $rows = array_values(array_filter($observations, fn ($r) => $session === 'all' || $r['session'] === $session));
                $train = array_values(array_filter($rows, fn ($r) => $r['end'] < $selectionAt));
                $select = array_values(array_filter($rows, fn ($r) => $r['at'] >= $selectionAt && $r['end'] < $testAt));
                $test = array_values(array_filter($rows, fn ($r) => $r['at'] >= $testAt));
                $this->assertWithinDeadline($runDeadline);
                if (min(count($train), count($select), count($test)) < $settings['min_block_rows']) {
                    continue;
                }
                $blocksReady = true;
                $fit = $this->fit($train, $runDeadline);
                if ($fit === null) {
                    continue;
                }
                $selection = $this->metrics($select, $fit, $settings, $runDeadline);
                $candidates[] = compact('lag', 'session', 'fit', 'selection', 'test') + [
                    'train_rows' => count($train), 'selection_rows' => count($select),
                    'train_labels_available_by_ms' => max(array_column($train, 'end')),
                    'selection_from_ms' => $selectionAt, 'selection_labels_available_by_ms' => max(array_column($select, 'end')),
                    'test_from_ms' => $testAt,
                ];
                $this->assertWithinDeadline($runDeadline);
            }
        }
        $report = ['samples' => $largest, 'minimum_samples' => $settings['min_samples'], 'context' => $context,
            'status' => 'insufficient_overlap', 'candidates' => [], 'selected' => null];
        if ($candidates === []) {
            $report['status'] = $blocksReady ? 'no_incremental_signal'
                : ($largest >= $settings['min_samples'] ? 'insufficient_chronological_blocks' : 'insufficient_overlap');
            $this->assertWithinDeadline($deadline);

            return ['model' => null, 'report' => $report];
        }
        $holdoutDeadline = TrainingRuntime::algorithmDeadline($deadline);
        $this->assertWithinDeadline($holdoutDeadline);
        usort($candidates, fn ($a, $b) => [-$a['selection']['skill'], $a['lag'], $a['session']]
            <=> [-$b['selection']['skill'], $b['lag'], $b['session']]);
        $winner = $candidates[0];
        $report['candidates'] = array_map(fn ($c) => ['lag' => $c['lag'], 'session' => $c['session'], 'selection' => $c['selection']], $candidates);
        $evaluation = $this->metrics($winner['test'], $winner['fit'], $settings, $holdoutDeadline);
        $blocks = array_chunk($winner['test'], (int) ceil(count($winner['test']) / 3));
        $persistence = array_map(fn ($block) => $this->metrics($block, $winner['fit'], $settings, $holdoutDeadline), $blocks);
        $sign = $winner['fit']['leader_beta'] <=> 0;
        $stable = count(array_filter($persistence, fn ($m) => $m['skill'] > 0 && $m['correlation'] * $sign > 0)) === count($persistence);
        $eligible = $winner['selection']['skill'] >= $settings['min_skill']
            && $winner['selection']['correlation'] * $sign >= $settings['min_correlation']
            && $evaluation['skill'] >= $settings['min_skill']
            && $settings['min_correlation'] <= $evaluation['correlation'] * $sign
            && $evaluation['correlation_lower_bound'] > 0
            && abs($evaluation['correlation']) >= abs($evaluation['reverse_correlation']) + $settings['min_direction_advantage']
            && $stable;
        $strength = $eligible ? min(1.0, $evaluation['correlation_lower_bound'] * sqrt(max(0, $evaluation['skill']))) : 0.0;
        $report['status'] = $eligible ? 'validated' : 'weak_or_unstable_evidence';
        $report['selected'] = $winner['lag'];
        $report['lag_ms'] = $winner['lag'] * $step;
        $report['session'] = $winner['session'];
        $report['direction'] = $sign > 0 ? 'same' : 'opposite';
        $report['strength'] = $strength;
        $report['evaluation'] = $evaluation;
        $report['persistence'] = $persistence;
        foreach (['train_rows', 'selection_rows', 'train_labels_available_by_ms', 'selection_from_ms', 'selection_labels_available_by_ms', 'test_from_ms'] as $key) {
            $report[$key] = $winner[$key];
        }
        $report['available_at_ms'] = max(array_column($winner['test'], 'end'));
        $report['session_basis'] = 'leader_local_time_at_observation';
        $report['market_context'] = ['leader_mean_volume' => $this->mean(array_column($winner['test'], 'volume')),
            'leader_mean_range' => $this->mean(array_column($winner['test'], 'range')),
            'follower_return_volatility' => $winner['fit']['scale']];
        $model = $eligible ? ['lag' => $winner['lag'], 'session' => $winner['session'], 'fit' => $winner['fit'],
            'strength' => $strength, 'available_at_ms' => $report['available_at_ms'], 'context' => $context] : null;
        $this->assertWithinDeadline($holdoutDeadline);

        return compact('model', 'report');
    }

    private function assertWithinDeadline(float $deadline): void
    {
        TrainingRuntime::heartbeat();

        if (microtime(true) > $deadline) {
            throw new RuntimeException('Lead/lag training time budget exceeded.');
        }
    }

    private function observations(array $leader, array $follower, int $step, int $lag, string $timezone, float $deadline): array
    {
        $rows = [];
        foreach ($leader as $time => $bar) {
            $this->assertWithinDeadline($deadline);
            $complete = isset($follower[$time]);
            for ($i = 1; $i <= $lag && $complete; $i++) {
                $complete = isset($follower[$time + $i * $step], $leader[$time + $i * $step]);
            }
            if (! $complete) {
                continue;
            }
            $end = $time + $lag * $step;
            $rows[] = ['at' => $time, 'end' => $end, 'x' => $bar['return'], 'z' => $follower[$time]['return'],
                'y' => $follower[$end]['return'], 'reverse' => $leader[$end]['return'],
                'volume' => $bar['volume'], 'range' => $bar['range'], 'session' => ExchangeTimezone::session($time, $timezone)];
        }
        $this->assertWithinDeadline($deadline);

        return $rows;
    }

    /** Two-variable regression isolates leader information beyond the follower's own current return. */
    private function fit(array $rows, float $deadline): ?array
    {
        $x = array_column($rows, 'x');
        $z = array_column($rows, 'z');
        $y = array_column($rows, 'y');
        $mx = $this->mean($x);
        $mz = $this->mean($z);
        $my = $this->mean($y);
        $xx = $this->covariance($x, $x);
        $zz = $this->covariance($z, $z);
        $xz = $this->covariance($x, $z);
        $xy = $this->covariance($x, $y);
        $zy = $this->covariance($z, $y);
        $determinant = $xx * $zz - $xz ** 2;
        $this->assertWithinDeadline($deadline);
        if ($xx < 1e-16 || $zz < 1e-16 || $determinant <= 1e-8 * $xx * $zz) {
            return null;
        }
        $bx = ($xy * $zz - $zy * $xz) / $determinant;
        $bz = ($zy * $xx - $xy * $xz) / $determinant;

        $fit = ['leader_beta' => $bx, 'follower_beta' => $bz, 'intercept' => $my - $bx * $mx - $bz * $mz,
            'baseline_beta' => $zy / $zz, 'baseline_intercept' => $my - $zy / $zz * $mz, 'prior' => $my,
            'scale' => max(1e-8, sqrt($this->covariance($y, $y)))];
        $this->assertWithinDeadline($deadline);

        return $fit;
    }

    private function metrics(array $rows, array $fit, array $settings, float $deadline): array
    {
        $loss = $baseline = $prior = 0.0;
        $residual = $x = $reverse = $z = [];
        foreach ($rows as $row) {
            $this->assertWithinDeadline($deadline);
            $prediction = $fit['intercept'] + $fit['leader_beta'] * $row['x'] + $fit['follower_beta'] * $row['z'];
            $local = $fit['baseline_intercept'] + $fit['baseline_beta'] * $row['z'];
            $loss += ($prediction - $row['y']) ** 2;
            $baseline += ($local - $row['y']) ** 2;
            $prior += ($fit['prior'] - $row['y']) ** 2;
            $residual[] = $row['y'] - $local;
            $x[] = $row['x'];
            $z[] = $row['z'];
            $reverse[] = $row['reverse'];
        }
        $correlation = $this->correlation($x, $residual);
        // Bartlett-style positive autocorrelation adjustment; never claim more independent rows than observed.
        $inflation = 1.0;
        for ($lag = 1; $lag <= min(10, intdiv(count($rows), 5)); $lag++) {
            $this->assertWithinDeadline($deadline);
            $inflation += 2 * max(0, $this->correlation(array_slice($x, $lag), array_slice($x, 0, -$lag))
                * $this->correlation(array_slice($residual, $lag), array_slice($residual, 0, -$lag)));
        }
        $effective = count($rows) / $inflation;
        $lower = $effective > 3 ? max(0, tanh(atanh(min(0.999999, abs($correlation))) - $settings['confidence_z'] / sqrt($effective - 3))) : 0.0;

        $metrics = ['rows' => count($rows), 'effective_rows' => $effective, 'correlation' => $correlation,
            'correlation_lower_bound' => $lower, 'reverse_correlation' => $this->correlation($z, $reverse),
            'skill' => min($baseline, $prior) > 1e-16 ? 1 - $loss / min($baseline, $prior) : 0.0,
            'mse' => $loss / max(1, count($rows)), 'baseline_mse' => min($baseline, $prior) / max(1, count($rows))];
        $this->assertWithinDeadline($deadline);

        return $metrics;
    }

    private function mean(array $values): float
    {
        return array_sum($values) / max(1, count($values));
    }

    private function covariance(array $a, array $b): float
    {
        $ma = $this->mean($a);
        $mb = $this->mean($b);
        $sum = 0.0;
        foreach ($a as $i => $value) {
            $sum += ($value - $ma) * ($b[$i] - $mb);
        }

        return $sum / max(1, count($a));
    }

    private function correlation(array $a, array $b): float
    {
        $denominator = sqrt(max(0, $this->covariance($a, $a) * $this->covariance($b, $b)));

        return $denominator > 1e-16 ? max(-1.0, min(1.0, $this->covariance($a, $b) / $denominator)) : 0.0;
    }
}
