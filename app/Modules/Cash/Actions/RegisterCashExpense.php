<?php

namespace App\Modules\Cash\Actions;

use App\Modules\Cash\Models\CashExpense;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/**
 * Registers an expense as a draft: the cash figure does not move until the draft is confirmed. The
 * same form submitted again with the very same details is a harmless retry, but a reused form with
 * changed details never overwrites the registered draft: it is rejected so nothing is silently
 * discarded. A missing receipt always needs a reason, at the action level too and not only in the
 * form validation.
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

        // The same form submitted again is not a new draft, and only the identical submission is a
        // retry: changed details mean the page is stale, so they are refused instead of discarded.
        $existing = CashExpense::query()->where('submission_key', $submissionKey)->first();
        if ($existing !== null) {
            return $this->resolve($existing, $amountCents, $spentOn, $category, $concept, $hasReceipt, $reason);
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
            // Two simultaneous submissions of the same form; resolve to the winner with the payload rule.
            $winner = CashExpense::query()->where('submission_key', $submissionKey)->first();

            if ($winner === null) {
                throw $e;
            }

            return $this->resolve($winner, $amountCents, $spentOn, $category, $concept, $hasReceipt, $reason);
        }
    }

    /**
     * @throws ValidationException
     */
    private function resolve(
        CashExpense $existing,
        int $amountCents,
        string $spentOn,
        string $category,
        string $concept,
        bool $hasReceipt,
        ?string $reason,
    ): CashExpense {
        $same = $existing->amount_cents === $amountCents
            && $existing->spent_on->toDateString() === $spentOn
            && $existing->category === $category
            && $existing->concept === $concept
            && $existing->has_receipt === $hasReceipt
            && $existing->reason === ($this->isEmpty($reason) ? null : trim((string) $reason));

        if (! $same) {
            throw ValidationException::withMessages([
                'submission_key' => 'El formulario ya se había enviado y sus datos han cambiado. Recarga la página y vuelve a intentarlo.',
            ]);
        }

        return $existing;
    }

    private function isEmpty(?string $reason): bool
    {
        return $reason === null || trim($reason) === '';
    }
}
