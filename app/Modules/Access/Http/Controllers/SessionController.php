<?php

namespace App\Modules\Access\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Access\Actions\AttemptLogin;
use App\Modules\Access\Http\Requests\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class SessionController extends Controller
{
    /**
     * How long the visitor has to present the second factor after the password.
     */
    private const CHALLENGE_MINUTES = 5;

    public function create(): View
    {
        return view('auth.login');
    }

    public function store(LoginRequest $request, AttemptLogin $attemptLogin): RedirectResponse
    {
        // A new password submission replaces any challenge left from a previous attempt.
        $this->forgetChallenge($request);

        $pending = $attemptLogin->handle($request->validated(), (string) $request->ip());

        if ($pending !== null) {
            $request->session()->put([
                'two_factor.pending_user_id' => $pending->getKey(),
                'two_factor.expires_at' => now()->addMinutes(self::CHALLENGE_MINUTES)->getTimestamp(),
            ]);

            // The password is not enough: the session stays guest until the code matches.
            return redirect('/login/segundo-factor');
        }

        $request->session()->regenerate();

        return redirect()->intended('/dashboard');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }

    private function forgetChallenge(Request $request): void
    {
        $request->session()->forget(['two_factor.pending_user_id', 'two_factor.expires_at']);
    }
}
