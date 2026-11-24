<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Hosting platforms terminate HTTPS at a proxy in front of the app; trust it so Laravel
        // sees the original https scheme and host (otherwise asset URLs come out as http://).
        $middleware->trustProxies(at: '*');

        $middleware->alias([
            'role' => \App\Http\Middleware\CheckRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Expected answers to the user (409/422 etc. with a message) are not server errors:
        // keep them out of the logs so real problems stand out. Canvas outages are still logged.
        $exceptions->dontReportWhen(fn (Throwable $e) => $e instanceof \App\Services\Adaptive\Quiz\Exceptions\QuizException
            && ! $e instanceof \App\Services\Adaptive\Canvas\CanvasUnavailable);
    })->create();
