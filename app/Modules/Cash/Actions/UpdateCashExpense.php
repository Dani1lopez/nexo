<?php

namespace App\Modules\Cash\Actions;

use App\Modules\Cash\Models\CashExpense;
use Illuminate\Validation\ValidationException;

/**
 * Edits a draft. A confirmed expense is closed: correcting it means voiding it with a reason and
 * registering a new one, so the recorded history of the money spent is never rewritten.
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
        if ($expense->isVoided()) {
            throw ValidationException::withMessages([
                'concept' => 'El gasto está anulado y no se puede editar.',
            ]);
        }

        if (! $expense->isDraft()) {
            throw ValidationException::withMessages([
                'concept' => 'El gasto está confirmado y no se puede editar: anúlalo con motivo y registra uno nuevo.',
            ]);
        }

        if (! $hasReceipt && ($reason === null || trim($reason) === '')) {
            throw ValidationException::withMessages([
                'reason' => 'Indica el motivo del gasto: no hay justificante.',
            ]);
        }

        $expense->forceFill([
            'amount_cents' => $amountCents,
            'spent_on' => $spentOn,
            'category' => $category,
            'concept' => $concept,
            'has_receipt' => $hasReceipt,
            'reason' => $reason === null || trim($reason) === '' ? null : trim($reason),
        ])->save();

        return $expense;
    }
}
