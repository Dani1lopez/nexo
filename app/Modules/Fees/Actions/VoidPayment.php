<?php

namespace App\Modules\Fees\Actions;

use App\Modules\Fees\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Voiding keeps the payment and its reason in the history.
 */
class VoidPayment
{
    public function handle(Payment $payment, string $reason): Payment
    {
        return DB::transaction(function () use ($payment, $reason): Payment {
            if ($payment->fresh()->voided_at !== null) {
                throw ValidationException::withMessages(['reason' => 'Este pago ya estaba anulado.']);
            }

            $payment->forceFill(['voided_at' => now(), 'void_reason' => trim($reason)])->save();

            return $payment;
        });
    }
}
