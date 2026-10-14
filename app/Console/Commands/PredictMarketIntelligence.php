<?php

namespace App\Console\Commands;

use App\Domain\Intelligence\MarketIntelligence;
use App\Domain\Intelligence\SignalFreshness;
use App\Domain\MarketData\CandleTimeframe;
use App\Models\Market;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

final class PredictMarketIntelligence extends Command
{
    protected $signature = 'trademinator:signal {exchange} {symbol} {period}
        {--raw : Compute a raw, unfiltered KNN proposal without advancing signal memory}
        {--recorded : Read the latest recorded Server observation without running inference}';

    protected $description = 'Explain the recorded Server signal, or explicitly inspect a raw KNN proposal';

    public function handle(MarketIntelligence $intelligence): int
    {
        try {
            (new CandleTimeframe)->next(0, $this->argument('period'));
            if ($this->option('raw') && $this->option('recorded')) {
                throw new RuntimeException('Choose --raw or --recorded, not both.');
            }
            $recorded = $this->option('recorded') || (config('signal_memory.enabled') && ! $this->option('raw'));
            $signal = $recorded ? $this->recordedSignal()
                : $intelligence->predict($this->argument('exchange'), $this->argument('symbol'), $this->argument('period'));
            $this->line(json_encode($signal, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }

    private function recordedSignal(): array
    {
        $market = Market::query()->with('feed', 'latestSignal')
            ->where('symbol', $this->argument('symbol'))
            ->whereHas('exchange', fn ($query) => $query->where('class', $this->argument('exchange')))->first();
        $signal = $market?->latestSignal;
        $period = $this->argument('period');
        if ($signal === null || $signal->period !== $period || $market->feed?->selected_period !== $period) {
            throw new RuntimeException('No recorded observation for this selected market period. Let the signal recorder run, or use --raw for an unfiltered diagnostic.');
        }
        $expiresAt = app(SignalFreshness::class)->expiresAt($signal->decision_at_ms, $period);
        $payload = $signal->payload;
        unset($payload['signal_memory_state']);
        $payload['observation'] = [
            'source' => 'journal', 'read_only' => true, 'signal_id' => $signal->getKey(),
            'recorded_at_ms' => $signal->recorded_at_ms, 'expires_at_ms' => $expiresAt,
            'is_fresh' => $signal->decision_at_ms !== null && $signal->decision_at_ms <= now()->getTimestampMs()
                && $expiresAt !== null && now()->getTimestampMs() < $expiresAt,
        ];

        return $payload;
    }
}
