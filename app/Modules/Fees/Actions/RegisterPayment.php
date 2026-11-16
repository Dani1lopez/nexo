<?php

namespace App\Modules\Fees\Actions;

use App\Modules\Fees\Models\Due;
use App\Modules\Fees\Models\Payment;
use App\Modules\Fees\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RegisterPayment
{
    public function handle(Due $due, int $amountCents, string $paidOn, string $method, ?string $note, string $submissionKey): Payment
    {
        // The pending amount is read inside the transaction (immediate on SQLite)
        // so two simultaneous payments cannot together exceed the due.
        return DB::transaction(function () use ($due, $amountCents, $paidOn, $method, $note, $submissionKey): Payment {
            // The same form submitted again (double click, reload, back button) is not a new payment.
            $existing = Payment::query()->where('submission_key', $submissionKey)->first();
            if ($existing !== null) {
                if ($existing->due_id !== $due->id) {
                    throw ValidationException::withMessages(['submission_key' => 'El formulario ha caducado. Recarga la página e inténtalo de nuevo.']);
                }

                return $existing;
            }

            $due = Due::with(['feeYear', 'exemptions', 'payments'])->findOrFail($due->id);

            if ($due->activeExemption() !== null) {
                throw ValidationException::withMessages(['amount' => 'La cuota está exenta y no admite pagos.']);
            }

            if ($amountCents > $due->pendingCents()) {
                throw ValidationException::withMessages(['amount' => 'El pago supera lo pendiente de la cuota ('.Money::format($due->pendingCents()).').']);
            }

            return Payment::forceCreate([
                'due_id' => $due->id,
                'amount_cents' => $amountCents,
                'paid_on' => $paidOn,
                'method' => $method,
                'submission_key' => $submissionKey,
                'note' => $note === null || trim($note) === '' ? null : trim($note),
            ]);
        });
    }
}
