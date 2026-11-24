<?php

namespace App\Providers;

use App\Services\Adaptive\Canvas\CanvasGateway;
use App\Services\Adaptive\Canvas\CanvasClient;
use App\Services\Adaptive\Canvas\FixtureCanvasGateway;
use App\Services\Adaptive\Canvas\RoutingCanvasGateway;
use App\Services\Adaptive\Canvas\TokenCanvasGateway;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Canvas adapter (v0.8 §3.1): 'fixture' uses sample data, 'token' the real Canvas API,
        // 'hybrid' both (each user by how their account was connected).
        $this->app->bind(CanvasGateway::class, function () {
            $adapter = config('adaptive.canvas.adapter', 'fixture');

            return match ($adapter) {
                'fixture' => new FixtureCanvasGateway(config('adaptive.canvas.fixture_path')),
                'token' => new TokenCanvasGateway(new CanvasClient()),
                // hybrid: demo accounts use the sample data, everyone else real Canvas (for a hosted demo).
                'hybrid' => new RoutingCanvasGateway(
                    new FixtureCanvasGateway(config('adaptive.canvas.fixture_path')),
                    new TokenCanvasGateway(new CanvasClient()),
                ),
                default => throw new InvalidArgumentException("Unknown Canvas adapter '{$adapter}'."),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Behind a hosting proxy, always generate https links when the site's address is https.
        if ($this->app->environment('production') && str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }
    }
}
