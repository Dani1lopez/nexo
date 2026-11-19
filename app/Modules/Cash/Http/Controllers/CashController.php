<?php

namespace App\Modules\Cash\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Cash\Actions\BuildCashBalance;
use App\Modules\Cash\Actions\RegisterOpeningBalance;
use App\Modules\Cash\Http\Requests\OpeningBalanceRequest;
use App\Modules\Cash\Models\CashOpeningBalance;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * The cash page: the figure the association keeps in the safe, its read-only breakdown for both roles
 * and the one-time base only treasury fixes. It will also host the cash expenses of issue #62.
 */
class CashController extends Controller
{
    public function index(BuildCashBalance $balance): View
    {
        return view('cash.index', [
            ...$balance->handle(),
            'opening' => CashOpeningBalance::query()->with('user')->first(),
        ]);
    }

    public function storeOpening(OpeningBalanceRequest $request, RegisterOpeningBalance $register): RedirectResponse
    {
        $opening = $register->handle($request->amountCents(), (int) $request->user()->id);

        return redirect('/caja')->with('status', $opening->wasRecentlyCreated
            ? 'Saldo inicial de caja fijado.'
            : 'El saldo inicial ya tenía ese importe: no se ha cambiado nada.');
    }
}
