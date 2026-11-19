<?php

namespace App\Modules\Cash\Actions;

use App\Modules\Cash\Models\CashExpense;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Voids a confirmed expense with a reason: the amount returns to the cash figure and the row stays
 * forever, so the recorded history of the money spent is never deleted. Drafts are edited, not
 * voided, and a voided expense can never be voided again.
 */
class VoidCashExpense
{
    /**
     * @throws ValidationException
     */
    public function handle(CashExpense $expense, string $reason): CashExpense
    {
        if ($expense->isVoided()) {
            throw ValidationException::withMessages([
                'reason' => 'El gasto ya está anulado.',
            ]);
        }

        if (! $expense->isConfirmed()) {
            throw ValidationException::withMessages([
                'reason' => 'El gasto está en borrador: edítalo en lugar de anularlo.',
            ]);
        }

        return DB::transaction(function () use ($expense, $reason): CashExpense {
            $fresh = CashExpense::query()->findOrFail($expense->id);

            if ($fresh->voided_at !== null) {
                throw ValidationException::withMessages([
                    'reason' => 'El gasto ya está anulado.',
                ]);
            }

            $fresh->forceFill([
                'voided_at' => now(),
                'void_reason' => $reason,
            ])->save();

            return $fresh;
        });
    }
}
