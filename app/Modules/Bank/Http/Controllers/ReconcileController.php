<?php

namespace App\Modules\Bank\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Bank\Actions\ReconcileMovement;
use App\Modules\Bank\Http\Requests\ReconcileRequest;
use App\Modules\Bank\Models\BankMovement;
use App\Modules\Fees\Actions\VoidPayment;
use App\Modules\Fees\Http\Requests\ReasonRequest;
use App\Modules\Fees\Models\Payment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class ReconcileController extends Controller
{
    public function store(ReconcileRequest $request, BankMovement $movement, ReconcileMovement $reconcile): RedirectResponse
    {
        $payments = $reconcile->handle($movement, $request->allocationsCents(), $request->validated('submission_key'));

        return redirect('/bank')->with('status', count($payments) > 1
            ? 'Reparto conciliado desde el extracto.'
            : 'Pago conciliado desde el extracto.');
    }

    public function void(ReasonRequest $request, Payment $payment, VoidPayment $void): RedirectResponse
    {
        // This screen only undoes a reconciliation, not a payment registered by hand.
        if ($payment->bank_movement_id === null) {
            throw ValidationException::withMessages([
                'payment' => 'Ese pago no procede de un movimiento del extracto.',
            ]);
        }

        $void->handle($payment, $request->validated('reason'));

        return redirect('/bank')->with('status', 'Pago anulado: el movimiento vuelve a estar libre.');
    }
}
