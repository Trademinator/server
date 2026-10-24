<?php

namespace App\Providers;

use App\Domain\Operations\ManagedRedisConnector;
use App\Domain\Operations\QueuePayload;
use App\Domain\Operations\WorkerRegistry;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class QueueManagementServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(WorkerRegistry::class);
        $this->app->singleton(QueuePayload::class);
        $this->app->afterResolving('queue', function ($manager, $app): void {
            $manager->addConnector('redis', fn (): ManagedRedisConnector => new ManagedRedisConnector($app['redis']));
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        Event::listen(Looping::class, fn () => app(WorkerRegistry::class)->guard(true));
        Event::listen(JobProcessing::class, function (JobProcessing $event): void {
            app(WorkerRegistry::class)->processing($event);
            app(QueuePayload::class)->processing($event);
        });
        Event::listen(JobAttempted::class, function (JobAttempted $event): void {
            try {
                app(WorkerRegistry::class)->attempted($event);
            } finally {
                app(QueuePayload::class)->attempted($event);
            }
        });
    }
}
