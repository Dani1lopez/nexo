<?php

namespace App\Modules\Cash\Actions;

use App\Modules\Cash\Models\CashExpense;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Edits a draft. A confirmed expense is closed: correcting it means voiding it with a reason and
 * registering a new one, so the recorded history of the money spent is never rewritten. The state
 * is checked and the write is made conditional on the row still being a draft, so an edit loaded
 * before a simultaneous confirmation can never overwrite the confirmed expense.
 */
class UpdateCashExpense
{
    /**
     * @throws ValidationException
     */
    public function handle(
        CashExpense $expense,
        int $amountCents,
        string $spentOn,
        string $category,
        string $concept,
        bool $hasReceipt,
        ?string $reason,
    ): CashExpense {
        if (! $hasReceipt && ($reason === null || trim($reason) === '')) {
            throw ValidationException::withMessages([
                'reason' => 'Indica el motivo del gasto: no hay justificante.',
            ]);
        }

        return DB::transaction(function () use ($expense, $amountCents, $spentOn, $category, $concept, $hasReceipt, $reason): CashExpense {
            $fresh = CashExpense::query()->findOrFail($expense->id);

            if ($fresh->isVoided()) {
                throw ValidationException::withMessages([
                    'concept' => 'El gasto está anulado y no se puede editar.',
                ]);
            }

            if (! $fresh->isDraft()) {
                throw ValidationException::withMessages([
                    'concept' => 'El gasto está confirmado y no se puede editar: anúlalo con motivo y registra uno nuevo.',
                ]);
            }

            // The row is only rewritten while it is still a draft, so a confirmation that lands in
            // between keeps its own amount and history untouched.
            $updated = CashExpense::query()
                ->whereKey($fresh->id)
                ->whereNull('confirmed_at')
                ->whereNull('voided_at')
                ->update([
                    'amount_cents' => $amountCents,
                    'spent_on' => $spentOn,
                    'category' => $category,
                    'concept' => $concept,
                    'has_receipt' => $hasReceipt,
                    'reason' => $reason === null || trim($reason) === '' ? null : trim($reason),
                ]);

            if ($updated === 0) {
                throw ValidationException::withMessages([
                    'concept' => 'El gasto ya no está en borrador: anúlalo con motivo y registra uno nuevo.',
                ]);
            }

            return $fresh->refresh();
        });
    }
}
