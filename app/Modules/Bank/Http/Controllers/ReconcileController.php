<?php

namespace App\Modules\Bank\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Bank\Actions\ReconcileMovement;
use App\Modules\Bank\Models\BankMovement;
use App\Modules\Fees\Actions\VoidPayment;
use App\Modules\Fees\Models\Due;
use App\Modules\Fees\Models\Payment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ReconcileController extends Controller
{
    public function store(Request $request, BankMovement $movement, ReconcileMovement $reconcile): RedirectResponse
    {
        $data = $request->validate([
            'due_id' => ['required', 'integer', 'exists:dues,id'],
        ]);

        $reconcile->handle($movement, Due::findOrFail($data['due_id']));

        return redirect('/bank')->with('status', 'Pago conciliado desde el extracto.');
    }

    public function void(Request $request, Payment $payment, VoidPayment $void): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $void->handle($payment, $data['reason']);

        return redirect('/bank')->with('status', 'Pago anulado: el movimiento vuelve a estar libre.');
    }
}
