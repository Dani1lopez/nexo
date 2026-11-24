<?php

namespace App\Modules\Demo\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Bank\Actions\ReconcileMovement;
use App\Modules\Bank\Models\BankMovement;
use App\Modules\Dashboard\Actions\BuildBalances;
use App\Modules\Demo\Actions\OpenDemoScenario;
use App\Modules\Demo\Http\Requests\DemoReconcileRequest;
use App\Modules\Demo\Support\DemoScenario;
use Illuminate\Http\Request;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class DemoController extends Controller
{
    /**
     * Step 1 and 2: the fixed scenario and the reconciliation form. Every request builds its own
     * throwaway database, so there is no state to read from a previous visitor.
     */
    public function show(OpenDemoScenario $sandbox): View
    {
        $scenario = $sandbox->handle(fn (DemoScenario $scenario): DemoScenario => $scenario);

        return view('demo.show', [
            'scenario' => $scenario,
            'balances' => null,
            'lines' => [],
            'result' => false,
            'demoErrors' => new MessageBag,
        ]);
    }

    /**
     * Step 2 and 3: the real reconciliation action over the throwaway database, followed by the
     * real balances summary. A rejected split is rendered in this same response, never through a
     * redirect with flashed errors or old input: the demo must not persist the visitor's request.
     */
    public function reconcile(Request $request, DemoReconcileRequest $validator, OpenDemoScenario $sandbox): View
    {
        $checked = $validator->validate($request->all());

        $data = $sandbox->handle(function (DemoScenario $scenario) use ($checked): array {
            if ($checked['errors']->isNotEmpty()) {
                return [
                    'scenario' => $scenario,
                    'balances' => null,
                    'lines' => [],
                    'result' => false,
                    'demoErrors' => $checked['errors'],
                ];
            }

            $amounts = $checked['cents'];

            $allocations = [];
            foreach ($scenario->keys() as $key) {
                if (isset($amounts[$key])) {
                    $allocations[$scenario->dueId($key)] = $amounts[$key];
                }
            }

            try {
                $payments = app(ReconcileMovement::class)->handle(
                    BankMovement::query()->findOrFail($scenario->movementId),
                    $allocations,
                    (string) Str::uuid(),
                );
            } catch (ValidationException $e) {
                // The real action refused the split (for example, a line above its due). The
                // messages belong to this response only; nothing is flashed to the session.
                return [
                    'scenario' => $scenario,
                    'balances' => null,
                    'lines' => [],
                    'result' => false,
                    'demoErrors' => new MessageBag($e->errors()),
                ];
            }

            // Display exactly what the real action stored, attributed through the sandbox dues.
            $stored = [];
            foreach ($payments as $payment) {
                $stored[(int) $payment->due_id] = (int) $payment->amount_cents;
            }

            $lines = [];
            foreach ($scenario->keys() as $key) {
                if (! isset($amounts[$key])) {
                    continue;
                }

                $dueId = $scenario->dueId($key);
                $lines[] = [
                    'name' => $scenario->memberName($key),
                    'year' => $scenario->year,
                    'amountCents' => $stored[$dueId],
                ];
            }

            return [
                'scenario' => $scenario,
                'balances' => app(BuildBalances::class)->handle(),
                'lines' => $lines,
                'result' => true,
                'demoErrors' => new MessageBag,
            ];
        });

        return view('demo.show', $data);
    }
}
