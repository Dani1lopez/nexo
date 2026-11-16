<?php

namespace App\Modules\Access\Authorization;

use App\Modules\Access\Models\User;
use Illuminate\Support\Facades\Gate;

class AccessGates
{
    public static function register(): void
    {
        Gate::define('view-treasury', fn (User $user): bool => in_array($user->role, ['treasury', 'board'], true));
        Gate::define('edit-treasury', fn (User $user): bool => $user->role === 'treasury');
    }
}
