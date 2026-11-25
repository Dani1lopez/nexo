<?php

namespace App\Modules\Demo\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Demo\Support\DemoSession;
use Illuminate\Http\RedirectResponse;

/**
 * Issue #74: the explicit, credential-free entry and exit of the demo session.
 *
 * The entry is a POST with the session token and its own throttle, and it only marks the visitor's
 * own session. The route is behind the `guest` middleware, which is the smallest possible refusal
 * for a real account: a signed-in visitor is sent back to their own panel and never gets the mark,
 * and the request middleware drops any leftover mark as soon as a real identity is present. The
 * exit always works, even if the mark is gone, so a failed exit cannot trap a visitor: both actions
 * are plain forms of the real screens.
 */
final class DemoSessionController extends Controller
{
    public function store(DemoSession $session): RedirectResponse
    {
        $session->start();

        // The demo starts on the first real screen, at its real URL: no interstitial, no detour.
        return redirect('/dashboard');
    }

    public function destroy(DemoSession $session): RedirectResponse
    {
        $session->stop();

        return redirect('/');
    }
}
