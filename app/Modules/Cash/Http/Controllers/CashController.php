<?php

namespace App\Modules\Cash\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Cash\Actions\BuildCashBalance;
use App\Modules\Cash\Actions\ConfirmCashExpense;
use App\Modules\Cash\Actions\RegisterCashExpense;
use App\Modules\Cash\Actions\RegisterOpeningBalance;
use App\Modules\Cash\Actions\UpdateCashExpense;
use App\Modules\Cash\Actions\VoidCashExpense;
use App\Modules\Cash\Http\Requests\ExpenseRequest;
use App\Modules\Cash\Http\Requests\OpeningBalanceRequest;
use App\Modules\Cash\Http\Requests\ReasonRequest;
use App\Modules\Cash\Models\CashExpense;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * The cash page: the figure the association keeps in the safe, its read-only breakdown for both
 * roles, the one-time base and the cash expenses of issue #62, which are drafts until treasury
 * confirms them and can only be corrected by a void with a reason.
 */
class CashController extends Controller
{
    public function index(BuildCashBalance $balance): View
    {
        // The action reads the opening row once and hands it over, so the figure and the displayed
        // base are the same row even if a treasury submission lands in between.
        return view('cash.index', [
            ...$balance->handle(),
            'expenses' => CashExpense::query()->with('user')->orderByDesc('id')->simplePaginate(50),
        ]);
    }

    public function storeOpening(OpeningBalanceRequest $request, RegisterOpeningBalance $register): RedirectResponse
    {
        $opening = $register->handle($request->amountCents(), (int) $request->user()->id);

        return redirect('/caja')->with('status', $opening->wasRecentlyCreated
            ? 'Saldo inicial de caja fijado.'
            : 'El saldo inicial ya tenía ese importe: no se ha cambiado nada.');
    }

    public function storeExpense(ExpenseRequest $request, RegisterCashExpense $register): RedirectResponse
    {
        $register->handle(
            (int) $request->user()->id,
            $request->amountCents(),
            (string) $request->input('spent_on'),
            $request->category(),
            $request->concept(),
            $request->hasReceipt(),
            $request->reason(),
            (string) $request->input('submission_key'),
        );

        return redirect('/caja')->with('status', 'Gasto en borrador registrado.');
    }

    public function editExpense(CashExpense $expense): View|RedirectResponse
    {
        // Only drafts are editable; a confirmed or voided expense has no edit page to revisit.
        if (! $expense->isDraft()) {
            return redirect('/caja');
        }

        return view('cash.edit', ['expense' => $expense]);
    }

    public function updateExpense(ExpenseRequest $request, UpdateCashExpense $update, CashExpense $expense): RedirectResponse
    {
        $update->handle(
            $expense,
            $request->amountCents(),
            (string) $request->input('spent_on'),
            $request->category(),
            $request->concept(),
            $request->hasReceipt(),
            $request->reason(),
        );

        return redirect('/caja')->with('status', 'Borrador actualizado.');
    }

    public function confirmExpense(ConfirmCashExpense $confirm, CashExpense $expense): RedirectResponse
    {
        $wasDraft = $expense->confirmed_at === null;
        $confirm->handle($expense);

        return redirect('/caja')->with('status', $wasDraft
            ? 'Gasto confirmado: descontado de la caja.'
            : 'El gasto ya estaba confirmado: no se ha cambiado nada.');
    }

    public function voidExpense(ReasonRequest $request, VoidCashExpense $void, CashExpense $expense): RedirectResponse
    {
        $void->handle($expense, (string) $request->input('reason'));

        return redirect('/caja')->with('status', 'Gasto anulado: el importe ha vuelto a la caja.');
    }
}
