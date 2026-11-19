<?php

namespace App\Modules\Bank\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Bank\Actions\ClassifyBankExpense;
use App\Modules\Bank\Actions\VoidBankExpense;
use App\Modules\Bank\Http\Requests\ClassifyExpenseRequest;
use App\Modules\Bank\Models\BankExpense;
use App\Modules\Bank\Models\BankMovement;
use App\Modules\Fees\Http\Requests\ReasonRequest;
use Illuminate\Http\RedirectResponse;

class BankExpenseController extends Controller
{
    public function store(ClassifyExpenseRequest $request, BankMovement $movement, ClassifyBankExpense $classify): RedirectResponse
    {
        $classify->handle(
            $movement,
            $request->validated('category'),
            $request->validated('description'),
            $request->validated('submission_key'),
            (int) $request->user()->id,
        );

        return redirect('/bank')->with('status', 'Cargo clasificado como gasto.');
    }

    public function void(ReasonRequest $request, BankExpense $expense, VoidBankExpense $void): RedirectResponse
    {
        $void->handle($expense, $request->validated('reason'));

        return redirect('/bank')->with('status', 'Gasto anulado: el cargo vuelve a estar sin clasificar.');
    }
}
