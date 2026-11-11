<?php

namespace App\Console\Commands;

use App\Domain\MarketData\CandlePeriodSelector;
use Illuminate\Console\Command;
use InvalidArgumentException;

use function Trademinator\Time\to_unixtime;

class SelectCandlePeriod extends Command
{
    protected $signature = 'trademinator:select-candle-period {exchange} {symbol} {--periods=1m,3m,5m,15m,30m,1h,4h,1d} {--tick-size=} {--threshold=0.7} {--coverage=0.8} {--minimum=0} {--sample=250} {--from=7 days ago} {--to=now}';

    protected $description = 'Select the shortest sufficiently informative candle period';

    public function handle(CandlePeriodSelector $selector): int
    {
        $tickSize = filter_var($this->option('tick-size'), FILTER_VALIDATE_FLOAT);
        $threshold = filter_var($this->option('threshold'), FILTER_VALIDATE_FLOAT);
        $coverage = filter_var($this->option('coverage'), FILTER_VALIDATE_FLOAT);
        $minimum = filter_var($this->option('minimum'), FILTER_VALIDATE_INT);
        $sample = filter_var($this->option('sample'), FILTER_VALIDATE_INT);
        $from = to_unixtime((string) $this->option('from'));
        $to = to_unixtime((string) $this->option('to'));
        if ($tickSize === false || $tickSize <= 0 || $from === false || $to === false || $from >= $to
            || $threshold === false || $threshold < 0 || $threshold > 1
            || $coverage === false || $coverage < 0 || $coverage > 1
            || $minimum === false || $minimum < 0 || $sample === false || $sample < max(1, $minimum)) {
            $this->error('Provide a positive --tick-size, valid interval, 0..1 threshold/coverage, and sample >= 1 and minimum >= 0.');

            return self::FAILURE;
        }
        try {
            $selected = $selector->select((string) $this->argument('exchange'), (string) $this->argument('symbol'),
                array_map('trim', explode(',', (string) $this->option('periods'))), $tickSize, $from, $to,
                $threshold, $coverage, $minimum, $sample);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        if ($selected === null) {
            $this->warn('No completed timeframe sample meets the candle-quality threshold and minimum size.');

            return self::FAILURE;
        }
        $this->line(json_encode($selected, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
