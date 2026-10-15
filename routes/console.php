<?php

use App\Jobs\TrainMarketIntelligence;
use Illuminate\Console\Application as ConsoleApplication;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Laravel schedule:run creates fresh PHP CLI processes for command() events.
// Explicitly add the same 512M limit to each spawned Artisan process; PHP -d
// options passed to the scheduler itself are not inherited across exec().
$scheduleArtisan = static fn (string $command) => Schedule::exec(sprintf(
    '%s -d memory_limit=512M %s %s',
    ConsoleApplication::phpBinary(),
    ConsoleApplication::artisanBinary(),
    $command,
));

// Every application node may run schedule:run. The shared cache elects one;
// the feed table's atomic claim also protects against duplicate dispatches.
$scheduleArtisan('trademinator:dispatch-market-feeds')
    ->everyMinute()->onOneServer()->withoutOverlapping(5);

$scheduleArtisan('trademinator:backfill-ohlcv')
    ->everyMinute()->onOneServer()->withoutOverlapping(5);

// Algorithm versions are reevaluated in small shared-market batches. The
// current selected period remains live until a replacement fully qualifies.
$scheduleArtisan('trademinator:evaluate-candle-period --outdated-only')
    ->everyFifteenMinutes()->onOneServer()->withoutOverlapping(15);

$scheduleArtisan('trademinator:collect-market-context')
    ->hourly()->onOneServer()->withoutOverlapping(60);

// GDELT publishes a new GKG batch roughly every 15 minutes. Poll cheaply every
// five minutes; the collector downloads a ZIP only when lastupdate.txt changes.
$scheduleArtisan('trademinator:collect-market-events')
    ->everyFiveMinutes()->onOneServer()->withoutOverlapping(15)->runInBackground();

$scheduleArtisan('trademinator:dispatch-market-features')
    ->everyMinute()->onOneServer()->withoutOverlapping(5);

$scheduleArtisan('trademinator:dispatch-market-signals')
    ->everyMinute()->onOneServer()->withoutOverlapping(5);

$scheduleArtisan('trademinator:refresh-market-discovery')
    ->hourlyAt(10)->onOneServer()->withoutOverlapping(15)->runInBackground();

// Every node has its own installed source and runtime metadata file. The command
// uses a local file lock and a shared cache lock around database synchronization.
$scheduleArtisan('trademinator:refresh-exchanges')->dailyAt('03:20');

// CPU-heavy training is drained on the intelligence queue by cron workers.
$scheduleArtisan('trademinator:dispatch-market-intelligence')
    ->cron(TrainMarketIntelligence::CRON)->onOneServer()->withoutOverlapping(10);

// Refit empirical cross-exchange evidence and its downstream model together.
$scheduleArtisan('trademinator:dispatch-lead-lag')
    ->dailyAt('03:45')->onOneServer()->withoutOverlapping(10);

$scheduleArtisan('trademinator:prune-access-statistics')
    ->dailyAt('02:40')->onOneServer()->withoutOverlapping(60);

// M4.3 exports and independently verifies complete old months. It never
// deletes hot rows; archive pruning remains deliberately disabled.
$scheduleArtisan('trademinator:archive-eligible-tickers')
    ->dailyAt('04:10')->onOneServer()->withoutOverlapping(120)->runInBackground();

// Prune expired multipart export/import staging files and metadata, never permanent archives.
$scheduleArtisan('trademinator:prune-portable-archives')
    ->dailyAt('04:40')->onOneServer()->withoutOverlapping(60)->runInBackground();
