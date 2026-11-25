<?php

namespace App\Modules\Demo\Http\Middleware;

use App\Modules\Access\Models\User;
use App\Modules\Bank\Actions\ReconcileMovement;
use App\Modules\Bank\Models\BankMovement;
use App\Modules\Demo\Actions\OpenDemoScenario;
use App\Modules\Demo\Support\DemoScenario;
use App\Modules\Demo\Support\DemoSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves a demo session on the real screens of issue #74.
 *
 * The visitor is still a guest: the mark of the session is not authentication. For the routes the
 * demo understands, this middleware opens the request's throwaway database, puts the example actor
 * of that database on the guard only for the lifetime of the request and applies the split the
 * visitor chose with the real ReconcileMovement. The response is rendered here, before the
 * connection and the guard are restored, because Laravel renders views after the middleware.
 *
 * Two safety rules shape the list of routes:
 *
 * - The sandbox is opened before `auth` (middleware priority, see bootstrap/app.php), so the guard,
 *   the route model bindings and every `@can` of the real views see the sandbox and its actor.
 * - Every other treasury route is answered with a redirect back to /dashboard *without* opening
 *   anything. A write the demo cannot serve never falls through to the treasury tables, and the
 *   real guard of that route is never relaxed to get there.
 */
final class OpenDemoSession
{
    /**
     * The real screens the demo serves: the guided walkthrough of the specification plus the
     * read-only screens its menu and its figures link to, and the single real write it needs.
     */
    private const SERVED = [
        'dashboard',
        'summary.show',
        'bank.index',
        'bank.movements.reconcile',
        'members.index',
        'fees.index',
        'fees.show',
        'cash.index',
    ];

    /** Every route name that belongs to the treasury, served or not. */
    private const TREASURY = [
        'dashboard',
        'summary.',
        'bank.',
        'members.',
        'fees.',
        'cash.',
        'exemptions.',
        'payments.',
        'two-factor.',
    ];

    public function __construct(
        private OpenDemoScenario $sandbox,
        private DemoSession $state,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->state->active()) {
            return $next($request);
        }

        // A real identity always wins over a leftover mark: nobody stays trapped in the demo.
        if (Auth::guard('web')->check()) {
            $this->state->stop();

            return $next($request);
        }

        $name = $request->route()?->getName();

        if ($name === null) {
            return $next($request);
        }

        if (! in_array($name, self::SERVED, true)) {
            return Str::startsWith($name, self::TREASURY) ? redirect('/dashboard') : $next($request);
        }

        // The demo builds a database on every request, so it is bounded like its own endpoint.
        return app(ThrottleRequests::class)->handle(
            $request,
            fn (Request $throttled): Response => $this->serve($throttled, $next, $name),
            'demo-request',
        );
    }

    /**
     * @param  Closure(Request): Response  $next
     */
    private function serve(Request $request, Closure $next, string $name): Response
    {
        return $this->sandbox->handle(function (DemoScenario $scenario) use ($request, $next, $name): Response {
            $request->attributes->set('demo.scenario', $scenario);

            // The actor is a row of this request's throwaway database and only lives in the guard
            // while the request runs: it is never written to the session as an authenticated user.
            Auth::guard('web')->setUser(User::query()->findOrFail($scenario->actorId));

            $this->replay($scenario);

            try {
                $result = $next($request);

                // A successful reconciliation is the only moment the demo stores anything.
                if ($name === 'bank.movements.reconcile') {
                    $this->remember($scenario);
                }

                return $result instanceof View ? new Response($result->render()) : $result;
            } finally {
                Auth::forgetGuards();
            }
        });
    }

    /**
     * Applies the split remembered in this visitor's session with the real action.
     *
     * A stored split the real rules no longer accept is discarded instead of breaking the request:
     * the demo always starts from a state its own scenario can explain.
     */
    private function replay(DemoScenario $scenario): void
    {
        $split = $this->state->split();

        if ($split === []) {
            return;
        }

        $allocations = [];
        foreach ($split as $key => $cents) {
            $allocations[$scenario->dueId($key)] = $cents;
        }

        try {
            app(ReconcileMovement::class)->handle(
                BankMovement::query()->findOrFail($scenario->movementId),
                $allocations,
                (string) Str::uuid(),
            );
        } catch (ValidationException) {
            $this->state->forgetSplit();
        }
    }

    /**
     * Keeps what the real action stored, with the scenario keys, never with database ids.
     */
    private function remember(DemoScenario $scenario): void
    {
        $paid = BankMovement::query()
            ->findOrFail($scenario->movementId)
            ->payments()
            ->whereNull('voided_at')
            ->get();

        $split = [];

        foreach ($scenario->keys() as $key) {
            $cents = (int) $paid->where('due_id', $scenario->dueId($key))->sum('amount_cents');

            if ($cents > 0) {
                $split[$key] = $cents;
            }
        }

        if ($split !== []) {
            $this->state->store($split);
        }
    }
}
