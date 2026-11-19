<?php

namespace App\Modules\Cash\Actions;

use App\Modules\Cash\Models\CashExpense;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Confirms a draft: from that moment the expense subtracts its amount from the cash figure, exactly
 * once. A repeated submit of the same confirmation finds the expense already confirmed and does
 * nothing, and a voided expense can never be confirmed again.
 */
class ConfirmCashExpense
{
    /**
     * @throws ValidationException
     */
    public function handle(CashExpense $expense): CashExpense
    {
        if ($expense->isVoided()) {
            throw ValidationException::withMessages([
                'concept' => 'El gasto está anulado y no se puede confirmar.',
            ]);
        }

        // A double click, a reload or a repeated submission: the money was already spent.
        if ($expense->confirmed_at !== null) {
            return $expense;
        }

        return DB::transaction(function () use ($expense): CashExpense {
            $fresh = CashExpense::query()->findOrFail($expense->id);

            if ($fresh->voided_at !== null) {
                throw ValidationException::withMessages([
                    'concept' => 'El gasto está anulado y no se puede confirmar.',
                ]);
            }

            if ($fresh->confirmed_at === null) {
                $fresh->forceFill(['confirmed_at' => now()])->save();
            }

            return $fresh;
        });
    }
}
