<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Une instance par requête/job (remise à zéro entre deux requêtes).
        $this->app->scoped(\App\Tenancy\TenantContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Découverte (mock serveur X) : 10 requêtes / minute / IP.
        \Illuminate\Support\Facades\RateLimiter::for('discover', fn (\Illuminate\Http\Request $request) => \Illuminate\Cache\RateLimiting\Limit::perMinute(10)
            ->by($request->ip())
            ->response(fn () => response()->json(['error' => 'TOO_MANY_REQUESTS', 'message' => 'Trop de requêtes.'], 429)));
    }
}
