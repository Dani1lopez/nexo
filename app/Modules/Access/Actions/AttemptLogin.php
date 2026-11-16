<?php

namespace App\Modules\Access\Actions;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AttemptLogin
{
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 60;

    /**
     * Authenticate validated credentials, throttled per normalized email and IP.
     *
     * @param  array<string, string>  $credentials
     *
     * @throws ValidationException
     */
    public function handle(array $credentials, string $ip): void
    {
        $key = 'login:'.hash('sha256', $credentials['email'].'|'.$ip);
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages(['email' => 'Demasiados intentos. Inténtalo de nuevo más tarde.']);
        }
        if (! Auth::attempt($credentials)) {
            RateLimiter::hit($key, self::DECAY_SECONDS);
            throw ValidationException::withMessages(['email' => 'Las credenciales no son válidas.']);
        }
        RateLimiter::clear($key);
    }
}
