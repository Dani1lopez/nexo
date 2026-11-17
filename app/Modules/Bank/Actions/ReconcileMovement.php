<?php

namespace App\Modules\Bank\Actions;

use App\Modules\Bank\Models\BankMovement;
use App\Modules\Fees\Actions\RegisterPayment;
use App\Modules\Fees\Models\Due;
use App\Modules\Fees\Models\Payment;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReconcileMovement
{
    public function __construct(private RegisterPayment $payments) {}

    /**
     * Turn a credit movement into a payment for one due, once.
     *
     * @throws ValidationException
     */
    public function handle(BankMovement $movement, Due $due): Payment
    {
        if ($movement->amount_cents <= 0) {
            throw ValidationException::withMessages([
                'due_id' => 'Solo se pueden conciliar abonos. Un cargo no genera un pago.',
            ]);
        }

        $active = $movement->activePayment();

        if ($active !== null) {
            // Submitting the same pair again (double click, reload) is not a new payment.
            if ($active->due_id === $due->id) {
                return $active;
            }

            throw ValidationException::withMessages([
                'due_id' => 'El movimiento ya está conciliado con otra cuota. Anula ese pago antes de conciliarlo de nuevo.',
            ]);
        }

        // The existing payment rules decide the rest: exempt dues and overpayments are refused there.
        return $this->payments->handle(
            $due,
            $movement->amount_cents,
            $movement->occurred_on->toDateString(),
            Payment::BANK,
            'Conciliado desde el extracto: '.$movement->concept,
            (string) Str::uuid(),
            $movement->id,
        );
    }
}
