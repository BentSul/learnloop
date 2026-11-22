<?php

namespace App\Providers;

use App\Services\Adaptive\Canvas\CanvasGateway;
use App\Services\Adaptive\Canvas\FixtureCanvasGateway;
use App\Services\Adaptive\Canvas\TokenCanvasGateway;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Canvas adapter (v0.8 §3.1): 'fixture' uses sample data, 'token' the real Canvas API.
        $this->app->bind(CanvasGateway::class, function () {
            $adapter = config('adaptive.canvas.adapter', 'fixture');

            return match ($adapter) {
                'fixture' => new FixtureCanvasGateway(config('adaptive.canvas.fixture_path')),
                'token' => new TokenCanvasGateway(new \App\Services\Adaptive\Canvas\CanvasClient()),
                default => throw new InvalidArgumentException("Unknown Canvas adapter '{$adapter}'."),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
