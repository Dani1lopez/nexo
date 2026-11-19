<?php

namespace App\Modules\Cash\Actions;

use App\Modules\Bank\Models\BankExpense;
use App\Modules\Bank\Models\BankMovement;
use App\Modules\Bank\Models\OtherIncome;
use App\Modules\Cash\Models\CashTransfer;
use App\Modules\Fees\Models\Payment;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Marks a complete statement movement as a transfer between the bank and the safe. It is never an
 * income or an expense: the bank balance already moved with the statement, and the cash figure moves
 * in the opposite or the same direction depending on where the money went.
 *
 * The movement must still be whole: no payment to the dues and no other live destination. The
 * database holds the same rules (see migration 2026_01_01_000017) because two simultaneous
 * submissions can pass these reads.
 */
class MarkCashTransfer
{
    private const BUSY = 'cash_transfers_bank_movement_busy';

    private const TRANSFERRED = 'cash_transfers_bank_movement_transferred';

    private const AMOUNT = 'cash_transfers_amount_mismatch';

    private const DIRECTION = 'cash_transfers_direction_mismatch';

    /**
     * @throws ValidationException
     */
    public function handle(
        BankMovement $movement,
        string $direction,
        string $description,
        string $submissionKey,
        int $userId,
    ): CashTransfer {
        $expected = $movement->amount_cents < 0 ? CashTransfer::TO_CASH : CashTransfer::TO_BANK;

        if (! in_array($direction, [CashTransfer::TO_CASH, CashTransfer::TO_BANK], true)) {
            throw ValidationException::withMessages([
                'direction' => 'Elige si el dinero va del banco a la caja o al contrario.',
            ]);
        }

        if ($direction !== $expected) {
            throw ValidationException::withMessages([
                'direction' => $movement->amount_cents < 0
                    ? 'Un cargo solo puede ser dinero que entra en la caja.'
                    : 'Un abono solo puede ser dinero que sale de la caja hacia el banco.',
            ]);
        }

        $description = trim($description);

        if ($description === '') {
            throw ValidationException::withMessages([
                'description' => 'Escribe una descripción del traspaso.',
            ]);
        }

        $replayed = $this->replayed($submissionKey, $movement, $direction, $description);

        if ($replayed !== null) {
            return $replayed;
        }

        try {
            return DB::transaction(function () use ($movement, $direction, $description, $submissionKey, $userId): CashTransfer {
                // Read inside the transaction: a concurrent void or classification must not be stale.
                $this->refuseUnlessAvailable($movement);

                return CashTransfer::forceCreate([
                    'bank_movement_id' => $movement->id,
                    'user_id' => $userId,
                    'direction' => $direction,
                    'description' => $description,
                    // The statement keeps its sign; the transfer stores the amount once, in absolute value.
                    'amount_cents' => abs((int) $movement->amount_cents),
                    'submission_key' => $submissionKey,
                ]);
            });
        } catch (QueryException $e) {
            throw $this->asValidationError($e);
        }
    }

    /**
     * The transfer an earlier submission of the same form already created, or null when this is new.
     *
     * @throws ValidationException when the form was already used (and voided, or resubmitted with other values)
     */
    private function replayed(string $submissionKey, BankMovement $movement, string $direction, string $description): ?CashTransfer
    {
        $existing = CashTransfer::query()->where('submission_key', $submissionKey)->first();

        if ($existing === null) {
            return null;
        }

        if ($existing->bank_movement_id !== $movement->id) {
            throw ValidationException::withMessages([
                'submission_key' => 'El formulario ha caducado. Recarga la página e inténtalo de nuevo.',
            ]);
        }

        if (! $existing->isActive()) {
            throw ValidationException::withMessages([
                'submission_key' => 'El formulario ha caducado: anulaste este traspaso. Recarga la página y vuelve a marcarlo.',
            ]);
        }

        if ($existing->direction !== $direction || $existing->description !== $description) {
            throw ValidationException::withMessages([
                'submission_key' => 'El formulario ya se usó con otra dirección o descripción. El movimiento conserva su traspaso original: anúlalo antes de volver a marcarlo.',
            ]);
        }

        return $existing;
    }

    /**
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
                'movement' => 'El movimiento ya tiene pagos asignados a cuotas. Anula esos pagos antes de marcarlo como traspaso.',
            ]);
        }

        $income = OtherIncome::query()
            ->where('bank_movement_id', $movement->id)
            ->whereNull('voided_at')
            ->exists();

        if ($income) {
            throw ValidationException::withMessages([
                'movement' => 'El movimiento está clasificado como otro ingreso: anula esa clasificación antes de marcarlo como traspaso.',
            ]);
        }

        $expense = BankExpense::query()
            ->where('bank_movement_id', $movement->id)
            ->whereNull('voided_at')
            ->exists();

        if ($expense) {
            throw ValidationException::withMessages([
                'movement' => 'El movimiento está clasificado como gasto: anula ese gasto antes de marcarlo como traspaso.',
            ]);
        }

        $transferred = CashTransfer::query()
            ->where('bank_movement_id', $movement->id)
            ->whereNull('voided_at')
            ->exists();

        if ($transferred) {
            throw ValidationException::withMessages([
                'movement' => 'El movimiento ya está marcado como traspaso.',
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function asValidationError(QueryException $e): ValidationException
    {
        $message = $e->getMessage();

        if (str_contains($message, self::TRANSFERRED)) {
            return ValidationException::withMessages(['movement' => 'El movimiento ya está marcado como traspaso.']);
        }

        if (str_contains($message, self::BUSY)) {
            return ValidationException::withMessages([
                'movement' => 'El movimiento ya tiene otro destino: anula ese pago o clasificación antes de marcarlo como traspaso.',
            ]);
        }

        if (str_contains($message, self::AMOUNT)) {
            return ValidationException::withMessages([
                'movement' => 'El movimiento ha cambiado mientras se marcaba: vuelve a intentarlo.',
            ]);
        }

        if (str_contains($message, self::DIRECTION)) {
            return ValidationException::withMessages([
                'direction' => 'La dirección del traspaso no coincide con el movimiento del extracto.',
            ]);
        }

        throw $e;
    }
}
