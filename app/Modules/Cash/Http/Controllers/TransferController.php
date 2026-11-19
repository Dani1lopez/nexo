<?php

namespace App\Modules\Cash\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Bank\Models\BankMovement;
use App\Modules\Cash\Actions\MarkCashTransfer;
use App\Modules\Cash\Actions\VoidCashTransfer;
use App\Modules\Cash\Http\Requests\CashTransferRequest;
use App\Modules\Cash\Models\CashTransfer;
use App\Modules\Fees\Http\Requests\ReasonRequest;
use Illuminate\Http\RedirectResponse;

class TransferController extends Controller
{
    public function store(CashTransferRequest $request, BankMovement $movement, MarkCashTransfer $mark): RedirectResponse
    {
        $mark->handle(
            $movement,
            $request->validated('direction'),
            $request->validated('description'),
            $request->validated('submission_key'),
            (int) $request->user()->id,
        );

        return redirect('/bank')->with('status', 'Movimiento marcado como traspaso.');
    }

    public function void(ReasonRequest $request, CashTransfer $transfer, VoidCashTransfer $void): RedirectResponse
    {
        $void->handle($transfer, $request->validated('reason'));

        return redirect('/bank')->with('status', 'Traspaso anulado: el movimiento vuelve a estar sin destino y la caja queda como estaba.');
    }
}
