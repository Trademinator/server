<?php

use App\Domain\Operations\ActionLog;
use App\Http\Middleware\AuthenticateClientApiKey;
use App\Http\Middleware\EnsureAccountActive;
use App\Http\Middleware\EnsureServerOwner;
use App\Http\Middleware\ObserveRequest;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->prepend(ObserveRequest::class);
        $middleware->trimStrings(except: ['credentials.secret', 'credentials.password', 'credentials.token']);
        $middleware->web(append: [EnsureAccountActive::class]);
        $middleware->alias([
            'server-owner' => EnsureServerOwner::class,
            'client-auth' => AuthenticateClientApiKey::class,
        ]);
        $middleware->prependToPriorityList(ThrottleRequests::class, AuthenticateClientApiKey::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, EnsureServerOwner::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->dontFlash(['credentials']);
        $exceptions->report(function (Throwable $error): void {
            $log = app(ActionLog::class);
            $log->write('exception.reported', ['outcome' => 'failed', ...$log->exception($error)]);
        });
    })->create();
