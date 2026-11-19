<?php

namespace App\Modules\Bank\Actions;

use App\Modules\Bank\Models\BankExpense;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Voiding a classified expense keeps it and its reason in the history and frees the charge again,
 * so it can be classified once more. The bank balance never moves: the charge was always counted.
 */
class VoidBankExpense
{
    public function handle(BankExpense $expense, string $reason): BankExpense
    {
        return DB::transaction(function () use ($expense, $reason): BankExpense {
            if ($expense->fresh()->voided_at !== null) {
                throw ValidationException::withMessages(['reason' => 'Este gasto ya estaba anulado.']);
            }

            $expense->forceFill(['voided_at' => now(), 'void_reason' => trim($reason)])->save();

            return $expense;
        });
    }
}
