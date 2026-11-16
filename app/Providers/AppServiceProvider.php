<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('view-treasury', fn (User $user): bool => in_array($user->role, ['treasury', 'board'], true));
        Gate::define('edit-treasury', fn (User $user): bool => $user->role === 'treasury');
    }
}
