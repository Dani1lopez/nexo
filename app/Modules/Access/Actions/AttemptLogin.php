<?php

namespace App\Modules\Access\Actions;

use App\Modules\Access\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AttemptLogin
{
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 60;

    /**
     * One message for every rejected attempt, so a blocked pair is not disclosed.
     */
    private const REJECTED_MESSAGE = 'Las credenciales no son válidas.';

    /**
     * Authenticate validated credentials, throttled per normalized email and IP.
     *
     * Returns the user when the password is correct but a confirmed second factor
     * still has to be presented: the guard does not log that user in yet.
     *
     * @param  array<string, string>  $credentials
     *
     * @throws ValidationException
     */
    public function handle(array $credentials, string $ip): ?User
    {
        $key = 'login:'.hash('sha256', $credentials['email'].'|'.$ip);
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages(['email' => self::REJECTED_MESSAGE]);
        }

        $pending = null;
        $authenticated = Auth::attemptWhen(
            $credentials,
            function (User $user) use (&$pending): bool {
                if (! $user->hasConfirmedTwoFactor()) {
                    return true;
                }

                // Keep the user out of the guard: the challenge completes the login.
                $pending = $user;

                return false;
            },
        );

        if ($authenticated) {
            RateLimiter::clear($key);

            return null;
        }

        if ($pending !== null) {
            // The password was right; only the code attempts are throttled now.
            RateLimiter::clear($key);

            // The challenge never sees the plaintext password, so this is the only moment
            // an outdated hash can be upgraded without asking for it again.
            if (Hash::needsRehash($pending->getAuthPassword())) {
                $pending->forceFill(['password' => $credentials['password']])->save();
            }

            return $pending;
        }

        RateLimiter::hit($key, self::DECAY_SECONDS);

        throw ValidationException::withMessages(['email' => self::REJECTED_MESSAGE]);
    }
}
