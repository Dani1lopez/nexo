<?php

namespace App\Providers;

use App\Modules\Access\Authorization\AccessGates;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Issue #74: the demo session has its own named budget, separate from the inline throttle
        // of /demo: entering the demo is a plain POST, and every request it serves in the sandbox
        // builds a throwaway database, so both are bounded per visitor without sharing counters.
        // They are attached when the limiter is first resolved, so booting the application never
        // fixes the cache store that backs the limiter.
        $this->app->resolving(RateLimiter::class, function (RateLimiter $limiter): void {
            $limiter->for('demo-entry', fn (Request $request): Limit => Limit::perMinute(10)->by((string) $request->ip()));
            $limiter->for('demo-request', fn (Request $request): Limit => Limit::perMinute(60)->by((string) $request->ip()));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        AccessGates::register();
    }
}
