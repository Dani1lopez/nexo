<?php

namespace App\Modules\Demo\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Demo\Support\DemoSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Issue #74: the explicit, credential-free entry and exit of the demo session.
 *
 * The entry is a POST with the session token and its own throttle, and it only marks the visitor's
 * own session. The route is behind the `guest` middleware, which is the smallest possible refusal
 * for a real account: a signed-in visitor is sent back to their own panel and never gets the mark,
 * and the request middleware drops any leftover mark as soon as a real identity is present. The
 * exit works even if the mark is gone, so a failed exit cannot trap a visitor, but it only discards
 * a session that actually carries the mark: a valid POST from a real account — or from any other
 * guest — leaves that session exactly as it was. Both actions are plain forms of the real screens.
 */
final class DemoSessionController extends Controller
{
    public function store(Request $request, DemoSession $session): RedirectResponse
    {
        // A browser can still hold an id a previous exit retired; marking that one would bounce it
        // back to /login until the retirement record expires (R1-001 / R4-001).
        if ($session->retired()) {
            $request->session()->regenerate();
        }

        $session->start();

        // The demo starts on the first real screen, at its real URL: no interstitial, no detour.
        return redirect('/dashboard');
    }

    public function destroy(Request $request, DemoSession $session): RedirectResponse
    {
        // A real identity — or a guest that never entered the demo — is never signed out here:
        // only a session that carries the mark is discarded, and the session token survives with
        // it for everyone else.
        $landing = Auth::check() ? '/dashboard' : '/';

        if (! $session->active()) {
            return redirect($landing);
        }

        // The routes of the demo hold the session lock, so no demo request is running while this
        // one discards the id; retiring it covers the response that was already rendered before
        // the lock, and invalidating hands the browser a fresh id so the old one is never reused.
        $session->retire();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect($landing);
    }
}
