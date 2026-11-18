<?php

namespace App\Modules\Bank\Actions;

use App\Modules\Bank\Models\BankMovement;
use App\Modules\Bank\Models\OtherIncome;
use App\Modules\Fees\Models\Payment;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Classifies a complete imported credit as other income instead of money for the dues.
 *
 * The credit is the only source of both destinations, so it must still be whole: no charge, no
 * part already paying a due and no other live classification. The database holds the same rules
 * (see migration 2026_01_01_000010) because two simultaneous submissions can pass these reads.
 */
class ClassifyOtherIncome
{
    private const BUSY = 'other_incomes_bank_movement_busy';

    private const CLASSIFIED = 'other_incomes_bank_movement_classified';

    private const AMOUNT = 'other_incomes_amount_mismatch';

    private const CHARGE = 'other_incomes_charge';

    /**
     * @throws ValidationException
     */
    public function handle(BankMovement $movement, string $category, string $description, string $submissionKey, int $userId): OtherIncome
    {
        if ($movement->amount_cents <= 0) {
            throw ValidationException::withMessages([
                'movement' => 'Solo se pueden clasificar abonos. Un cargo no es un ingreso.',
            ]);
        }

        if (! array_key_exists($category, OtherIncome::CATEGORIES)) {
            throw ValidationException::withMessages([
                'category' => 'Elige una de las categorías disponibles.',
            ]);
        }

        $description = trim($description);

        if ($description === '') {
            throw ValidationException::withMessages([
                'description' => 'Escribe una descripción para el ingreso.',
            ]);
        }

        // Resending the same form finds its classification instead of creating a second one.
        $replayed = $this->replayed($submissionKey, $movement, $category, $description);

        if ($replayed !== null) {
            return $replayed;
        }

        try {
            return DB::transaction(function () use ($movement, $category, $description, $submissionKey, $userId): OtherIncome {
                // Read inside the transaction: a concurrent void or payment must not leave it stale.
                $this->refuseUnlessAvailable($movement);

                return OtherIncome::forceCreate([
                    'bank_movement_id' => $movement->id,
                    'user_id' => $userId,
                    'category' => $category,
                    'description' => $description,
                    'amount_cents' => $movement->amount_cents,
                    'submission_key' => $submissionKey,
                ]);
            });
        } catch (QueryException $e) {
            throw $this->asValidationError($e);
        }
    }

    /**
     * The classification an earlier submission of the same form already created, or null when this is new.
     *
     * Only an identical payload counts as the same submission: the form key survives a reload, so the
     * same key carrying other values is a second, different classification attempt.
     *
     * @throws ValidationException when the form was already used (and voided, or resubmitted with other values)
     */
    private function replayed(string $submissionKey, BankMovement $movement, string $category, string $description): ?OtherIncome
    {
        $existing = OtherIncome::query()->where('submission_key', $submissionKey)->first();

        if ($existing === null) {
            return null;
        }

        // A key belongs to the movement it was rendered for; reusing it elsewhere is a stale form.
        if ($existing->bank_movement_id !== $movement->id) {
            throw ValidationException::withMessages([
                'submission_key' => 'El formulario ha caducado. Recarga la página e inténtalo de nuevo.',
            ]);
        }

        // Replaying a voided classification would announce income that no longer exists, and
        // inserting it again would collide with the unique key of the voided row: expired.
        if (! $existing->isActive()) {
            throw ValidationException::withMessages([
                'submission_key' => 'El formulario ha caducado: anulaste esta clasificación. Recarga la página y clasifícalo de nuevo.',
            ]);
        }

        // The key identifies one payload. Accepting other values here would store neither of them
        // and still report success, so the credit keeps what it already has until it is voided.
        if ($existing->category !== $category || $existing->description !== $description) {
            throw ValidationException::withMessages([
                'submission_key' => 'El formulario ya se usó con otra categoría o descripción. El abono conserva su clasificación original: anula la clasificación antes de volver a clasificarlo.',
            ]);
        }

        return $existing;
    }

    /**
     * A credit is classifiable only while no active payment and no other live classification use it.
     *
     * @throws ValidationException
     */
    private function refuseUnlessAvailable(BankMovement $movement): void
    {
        $paid = Payment::query()
            ->where('bank_movement_id', $movement->id)
            ->whereNull('voided_at')
            ->exists();

        if ($paid) {
            throw ValidationException::withMessages([
                'movement' => 'El abono ya tiene pagos asignados a cuotas. Anula esos pagos antes de clasificarlo como otro ingreso.',
            ]);
        }

        $classified = OtherIncome::query()
            ->where('bank_movement_id', $movement->id)
            ->whereNull('voided_at')
            ->exists();

        if ($classified) {
            throw ValidationException::withMessages([
                'movement' => 'El abono ya está clasificado como otro ingreso.',
            ]);
        }
    }

    /**
     * Turns a constraint the database raised on this classification into the message the screen shows.
     * Any other error is rethrown: an unexpected failure must never look like a refused input.
     *
     * @throws ValidationException
     */
    private function asValidationError(QueryException $e): ValidationException
    {
        $message = $e->getMessage();

        if (str_contains($message, self::CLASSIFIED)) {
            return ValidationException::withMessages(['movement' => 'El abono ya está clasificado como otro ingreso.']);
        }

        if (str_contains($message, self::BUSY)) {
            return ValidationException::withMessages([
                'movement' => 'El abono ya tiene pagos asignados a cuotas. Anula esos pagos antes de clasificarlo como otro ingreso.',
            ]);
        }

        if (str_contains($message, self::AMOUNT)) {
            return ValidationException::withMessages([
                'movement' => 'El abono ha cambiado mientras se clasificaba: vuelve a intentarlo.',
            ]);
        }

        if (str_contains($message, self::CHARGE)) {
            return ValidationException::withMessages(['movement' => 'Solo se pueden clasificar abonos. Un cargo no es un ingreso.']);
        }

        throw $e;
    }
}
