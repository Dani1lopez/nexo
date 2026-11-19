<?php

namespace App\Modules\Cash\Actions;

use App\Modules\Cash\Models\CashExpense;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/**
 * Registers an expense as a draft: the cash figure does not move until the draft is confirmed. The
 * same form submitted twice (double click, reload) is not a second draft, and a missing receipt
 * always needs a reason, at the action level too and not only in the form validation.
 */
class RegisterCashExpense
{
    /**
     * @throws ValidationException
     */
    public function handle(
        int $userId,
        int $amountCents,
        string $spentOn,
        string $category,
        string $concept,
        bool $hasReceipt,
        ?string $reason,
        string $submissionKey,
    ): CashExpense {
        if (! $hasReceipt && $this->isEmpty($reason)) {
            throw ValidationException::withMessages([
                'reason' => 'Indica el motivo del gasto: no hay justificante.',
            ]);
        }

        // The same form submitted again is not a new draft.
        $existing = CashExpense::query()->where('submission_key', $submissionKey)->first();
        if ($existing !== null) {
            return $existing;
        }

        try {
            return CashExpense::forceCreate([
                'user_id' => $userId,
                'amount_cents' => $amountCents,
                'spent_on' => $spentOn,
                'category' => $category,
                'concept' => $concept,
                'has_receipt' => $hasReceipt,
                'reason' => $this->isEmpty($reason) ? null : trim((string) $reason),
                'submission_key' => $submissionKey,
            ]);
        } catch (UniqueConstraintViolationException $e) {
            // Two simultaneous submissions of the same form; resolve to the winner.
            $winner = CashExpense::query()->where('submission_key', $submissionKey)->first();

            if ($winner === null) {
                throw $e;
            }

            return $winner;
        }
    }

    private function isEmpty(?string $reason): bool
    {
        return $reason === null || trim($reason) === '';
    }
}
