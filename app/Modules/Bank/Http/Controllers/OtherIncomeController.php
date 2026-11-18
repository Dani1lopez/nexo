<?php

namespace App\Modules\Bank\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Bank\Actions\ClassifyOtherIncome;
use App\Modules\Bank\Actions\VoidOtherIncome;
use App\Modules\Bank\Http\Requests\ClassifyIncomeRequest;
use App\Modules\Bank\Models\BankMovement;
use App\Modules\Bank\Models\OtherIncome;
use App\Modules\Fees\Http\Requests\ReasonRequest;
use Illuminate\Http\RedirectResponse;

class OtherIncomeController extends Controller
{
    public function store(ClassifyIncomeRequest $request, BankMovement $movement, ClassifyOtherIncome $classify): RedirectResponse
    {
        $classify->handle(
            $movement,
            $request->validated('category'),
            $request->validated('description'),
            $request->validated('submission_key'),
            (int) $request->user()->id,
        );

        return redirect('/bank')->with('status', 'Abono clasificado como otro ingreso.');
    }

    public function void(ReasonRequest $request, OtherIncome $income, VoidOtherIncome $void): RedirectResponse
    {
        $void->handle($income, $request->validated('reason'));

        return redirect('/bank')->with('status', 'Clasificación anulada: el abono vuelve a estar pendiente de conciliación.');
    }
}
