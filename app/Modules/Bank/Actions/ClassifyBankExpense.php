<?php

namespace App\Modules\Bank\Actions;

use App\Modules\Bank\Models\BankExpense;
use App\Modules\Bank\Models\BankMovement;
use App\Modules\Bank\Models\OtherIncome;
use App\Modules\Cash\Models\CashTransfer;
use App\Modules\Fees\Models\Payment;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Classifies a complete imported charge as an expense, so the demo can explain what the money was
 * spent on. The bank balance already subtracts the charge from the statement, so this never inserts
 * or rewrites a movement: it only labels one.
 *
 * The charge must still be whole: no credit, no charge already paying a due and no other live
 * destination. The database holds the same rules (see migration 2026_01_01_000016) because two
 * simultaneous submissions can pass these reads.
 */
class ClassifyBankExpense
{
    private const BUSY = 'bank_expenses_bank_movement_busy';

    private const CLASSIFIED = 'bank_expenses_bank_movement_classified';

    private const AMOUNT = 'bank_expenses_amount_mismatch';

    private const CREDIT = 'bank_expenses_credit';

    private const TRANSFERRED = 'bank_movement_has_transfer';

    /**
     * @throws ValidationException
     */
    public function handle(BankMovement $movement, string $category, string $description, string $submissionKey, int $userId): BankExpense
    {
        if ($movement->amount_cents >= 0) {
            throw ValidationException::withMessages([
                'movement' => 'Solo se pueden clasificar cargos. Un abono no es un gasto: concílialo o clasifícalo como otro ingreso.',
            ]);
        }

        if (! array_key_exists($category, BankExpense::CATEGORIES)) {
            throw ValidationException::withMessages([
                'category' => 'Elige una de las categorías disponibles.',
            ]);
        }

        $description = trim($description);

        if ($description === '') {
            throw ValidationException::withMessages([
                'description' => 'Escribe una descripción del gasto.',
            ]);
        }

        // Resending the same form finds its classification instead of creating a second one.
        $replayed = $this->replayed($submissionKey, $movement, $category, $description);

        if ($replayed !== null) {
            return $replayed;
        }

        try {
            return DB::transaction(function () use ($movement, $category, $description, $submissionKey, $userId): BankExpense {
                // Read inside the transaction: a concurrent void or payment must not leave it stale.
                $this->refuseUnlessAvailable($movement);

                return BankExpense::forceCreate([
                    'bank_movement_id' => $movement->id,
                    'user_id' => $userId,
                    'category' => $category,
                    'description' => $description,
                    // The charge is negative on the statement; the expense stores it as a positive amount.
                    'amount_cents' => abs((int) $movement->amount_cents),
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
    private function replayed(string $submissionKey, BankMovement $movement, string $category, string $description): ?BankExpense
    {
        $existing = BankExpense::query()->where('submission_key', $submissionKey)->first();

        if ($existing === null) {
            return null;
        }

        // A key belongs to the movement it was rendered for; reusing it elsewhere is a stale form.
        if ($existing->bank_movement_id !== $movement->id) {
            throw ValidationException::withMessages([
                'submission_key' => 'El formulario ha caducado. Recarga la página e inténtalo de nuevo.',
            ]);
        }

        // Replaying a voided classification would announce a spending that no longer exists, and
        // inserting it again would collide with the unique key of the voided row: expired.
        if (! $existing->isActive()) {
            throw ValidationException::withMessages([
                'submission_key' => 'El formulario ha caducado: anulaste este gasto. Recarga la página y clasifícalo de nuevo.',
            ]);
        }

        // The key identifies one payload. Accepting other values here would store neither of them
        // and still report success, so the charge keeps what it already has until it is voided.
        if ($existing->category !== $category || $existing->description !== $description) {
            throw ValidationException::withMessages([
                'submission_key' => 'El formulario ya se usó con otra categoría o descripción. El cargo conserva su clasificación original: anula el gasto antes de volver a clasificarlo.',
            ]);
        }

        return $existing;
    }

    /**
     * A charge is classifiable only while no active payment and no other live destination use it.
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
                'movement' => 'El movimiento ya tiene pagos asignados a cuotas. Anula esos pagos antes de clasificarlo como gasto.',
            ]);
        }

        $income = OtherIncome::query()
            ->where('bank_movement_id', $movement->id)
            ->whereNull('voided_at')
            ->exists();

        if ($income) {
            throw ValidationException::withMessages([
                'movement' => 'El movimiento ya está clasificado como otro ingreso: anula esa clasificación antes de clasificarlo como gasto.',
            ]);
        }

        // A movement has one destination: once it is a transfer it cannot become an expense too.
        $transferred = CashTransfer::query()
            ->where('bank_movement_id', $movement->id)
            ->whereNull('voided_at')
            ->exists();

        if ($transferred) {
            throw ValidationException::withMessages([
                'movement' => 'El movimiento ya está marcado como traspaso: anúlalo antes de clasificarlo como gasto.',
            ]);
        }

        $classified = BankExpense::query()
            ->where('bank_movement_id', $movement->id)
            ->whereNull('voided_at')
            ->exists();

        if ($classified) {
            throw ValidationException::withMessages([
                'movement' => 'El cargo ya está clasificado como gasto.',
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
            return ValidationException::withMessages(['movement' => 'El cargo ya está clasificado como gasto.']);
        }

        if (str_contains($message, self::BUSY)) {
            return ValidationException::withMessages([
                'movement' => 'El movimiento ya tiene otro destino: anula ese pago o clasificación antes de clasificarlo como gasto.',
            ]);
        }

        if (str_contains($message, self::AMOUNT)) {
            return ValidationException::withMessages([
                'movement' => 'El cargo ha cambiado mientras se clasificaba: vuelve a intentarlo.',
            ]);
        }

        if (str_contains($message, self::TRANSFERRED)) {
            return ValidationException::withMessages([
                'movement' => 'El movimiento ya está marcado como traspaso: anúlalo antes de clasificarlo como gasto.',
            ]);
        }

        if (str_contains($message, self::CREDIT)) {
            return ValidationException::withMessages([
                'movement' => 'Solo se pueden clasificar cargos. Un abono no es un gasto: concílialo o clasifícalo como otro ingreso.',
            ]);
        }

        throw $e;
    }
}
