<?php

namespace App\Modules\Fees\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Fees\Actions\RegisterPayment;
use App\Modules\Fees\Actions\VoidPayment;
use App\Modules\Fees\Http\Requests\PaymentRequest;
use App\Modules\Fees\Http\Requests\ReasonRequest;
use App\Modules\Fees\Models\Due;
use App\Modules\Fees\Models\Payment;
use Illuminate\Http\RedirectResponse;

class PaymentController extends Controller
{
    public function store(PaymentRequest $request, Due $due, RegisterPayment $register): RedirectResponse
    {
        $register->handle($due, $request->amountCents(), $request->validated('paid_on'), $request->validated('method'), $request->validated('note'));

        return redirect("/fees/{$due->fee_year_id}");
    }

    public function void(ReasonRequest $request, Payment $payment, VoidPayment $void): RedirectResponse
    {
        $void->handle($payment, $request->validated('reason'));

        return redirect('/fees/'.$payment->due->fee_year_id);
    }
}
