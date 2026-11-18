<?php

namespace App\Modules\Access\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Access\Http\Requests\TwoFactorCodeRequest;
use App\Modules\Access\Models\User;
use App\Modules\Access\Support\Totp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

class TwoFactorController extends Controller
{
    private const CODE_MAX_ATTEMPTS = 5;

    private const CODE_DECAY_SECONDS = 60;

    /**
     * One message for a wrong code and for a throttled pair, so neither is disclosed.
     */
    private const REJECTED_MESSAGE = 'El código no es válido.';

    private const EXPIRED_MESSAGE = 'La verificación ha caducado. Vuelve a iniciar sesión.';

    public function index(Request $request): View
    {
        $user = $this->currentUser($request);

        $secret = $user->two_factor_secret;
        $uri = $secret !== null && ! $user->hasConfirmedTwoFactor()
            ? (new Totp)->otpauthUri($secret, (string) $user->email, 'Nexo')
            : null;

        return view('access.two-factor', [
            'user' => $user,
            'secret' => $secret,
            'uri' => $uri,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->currentUser($request);

        if ($user->hasConfirmedTwoFactor()) {
            return redirect('/access/two-factor')
                ->with('status', 'La verificación en dos pasos ya está activada.');
        }

        $user->two_factor_secret = (new Totp)->generateSecret();
        $user->two_factor_confirmed_at = null;
        $user->save();

        return redirect('/access/two-factor')
            ->with('status', 'Secreto generado: confirma el código para activar la verificación en dos pasos.');
    }

    public function confirm(TwoFactorCodeRequest $request): RedirectResponse
    {
        $user = $this->currentUser($request);
        $secret = $user->two_factor_secret;

        if ($secret === null || $user->hasConfirmedTwoFactor()) {
            return redirect('/access/two-factor')
                ->withErrors(['code' => 'No hay una activación pendiente.']);
        }

        if (! (new Totp)->verify($secret, (string) $request->validated('code'))) {
            return redirect('/access/two-factor')->withErrors(['code' => self::REJECTED_MESSAGE]);
        }

        $user->two_factor_confirmed_at = now();
        $user->save();

        return redirect('/access/two-factor')->with('status', 'Verificación en dos pasos activada.');
    }

    public function disable(TwoFactorCodeRequest $request): RedirectResponse
    {
        $user = $this->currentUser($request);
        $secret = $user->two_factor_secret;

        if ($secret === null || ! $user->hasConfirmedTwoFactor()) {
            return redirect('/access/two-factor')
                ->withErrors(['code' => 'La verificación en dos pasos no está activada.']);
        }

        if (! (new Totp)->verify($secret, (string) $request->validated('code'))) {
            return redirect('/access/two-factor')->withErrors(['code' => self::REJECTED_MESSAGE]);
        }

        $user->two_factor_secret = null;
        $user->two_factor_confirmed_at = null;
        $user->save();

        return redirect('/access/two-factor')->with('status', 'Verificación en dos pasos desactivada.');
    }

    public function challenge(Request $request): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect('/dashboard');
        }

        if ($this->pendingUser($request) === null) {
            $this->forgetChallenge($request);

            return redirect('/login')->withErrors(['code' => self::EXPIRED_MESSAGE]);
        }

        return view('access.two-factor-challenge');
    }

    public function challengeStore(TwoFactorCodeRequest $request): RedirectResponse
    {
        if (Auth::check()) {
            return redirect('/dashboard');
        }

        $user = $this->pendingUser($request);
        $secret = $user?->two_factor_secret;

        if ($user === null || $secret === null || $secret === '') {
            $this->forgetChallenge($request);

            return redirect('/login')->withErrors(['code' => self::EXPIRED_MESSAGE]);
        }

        $key = 'two-factor:'.hash('sha256', $user->getKey().'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($key, self::CODE_MAX_ATTEMPTS)) {
            return redirect('/login/segundo-factor')->withErrors(['code' => self::REJECTED_MESSAGE]);
        }

        if (! (new Totp)->verify($secret, (string) $request->validated('code'))) {
            RateLimiter::hit($key, self::CODE_DECAY_SECONDS);

            return redirect('/login/segundo-factor')->withErrors(['code' => self::REJECTED_MESSAGE]);
        }

        RateLimiter::clear($key);
        $this->forgetChallenge($request);
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended('/dashboard');
    }

    private function currentUser(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    /**
     * Resolve the pending login only while the five-minute window is still open.
     */
    private function pendingUser(Request $request): ?User
    {
        $id = $request->session()->get('two_factor.pending_user_id');
        $expires = $request->session()->get('two_factor.expires_at');

        if (! is_int($id) || ! is_int($expires) || $expires < now()->getTimestamp()) {
            return null;
        }

        $user = User::find($id);
        if (! $user instanceof User || ! $user->hasConfirmedTwoFactor()) {
            return null;
        }

        return $user;
    }

    private function forgetChallenge(Request $request): void
    {
        $request->session()->forget(['two_factor.pending_user_id', 'two_factor.expires_at']);
    }
}
