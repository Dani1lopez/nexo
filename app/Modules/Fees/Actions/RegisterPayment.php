<?php

namespace App\Modules\Fees\Actions;

use App\Modules\Bank\Models\BankMovement;
use App\Modules\Fees\Models\Due;
use App\Modules\Fees\Models\Payment;
use App\Modules\Fees\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RegisterPayment
{
    /**
     * Abort message of the SQLite capacity trigger (see migration 2026_01_01_000009).
     */
    private const MOVEMENT_CAPACITY = 'payments_bank_movement_capacity';

    public function handle(Due $due, int $amountCents, string $paidOn, string $method, ?string $note, string $submissionKey, ?int $bankMovementId = null): Payment
    {
        // The pending amount is read inside the transaction (immediate on SQLite)
        // so two simultaneous payments cannot together exceed the due.
        return DB::transaction(function () use ($due, $amountCents, $paidOn, $method, $note, $submissionKey, $bankMovementId): Payment {
            // The same form submitted again (double click, reload, back button) is not a new payment.
            $existing = Payment::query()->where('submission_key', $submissionKey)->first();
            if ($existing !== null) {
                if ($existing->due_id !== $due->id) {
                    throw ValidationException::withMessages(['submission_key' => 'El formulario ha caducado. Recarga la página e inténtalo de nuevo.']);
                }

                return $existing;
            }

            $due = Due::with(['feeYear', 'exemptions', 'payments'])->findOrFail($due->id);

            // Read inside the transaction: the same movement and due pair is not paid twice.
            if ($bankMovementId !== null) {
                $pair = Payment::query()
                    ->where('bank_movement_id', $bankMovementId)
                    ->where('due_id', $due->id)
                    ->whereNull('voided_at')
                    ->orderBy('id')
                    ->first();

                if ($pair !== null) {
                    return $pair;
                }

                // A movement's capacity is its own amount: the sum of its active payments never exceeds it.
                $free = $this->freeCentsOf($bankMovementId);

                if ($amountCents > $free) {
                    throw ValidationException::withMessages(['amount' => 'El movimiento no tiene importe libre suficiente: quedan '.Money::format($free).' por conciliar.']);
                }
            }

            if ($due->activeExemption() !== null) {
                throw ValidationException::withMessages(['amount' => 'La cuota está exenta y no admite pagos.']);
            }

            if ($amountCents > $due->pendingCents()) {
                throw ValidationException::withMessages(['amount' => 'El pago supera lo pendiente de la cuota ('.Money::format($due->pendingCents()).').']);
            }

            try {
                return Payment::forceCreate([
                    'due_id' => $due->id,
                    'bank_movement_id' => $bankMovementId,
                    'amount_cents' => $amountCents,
                    'paid_on' => $paidOn,
                    'method' => $method,
                    'submission_key' => $submissionKey,
                    'note' => $note === null || trim($note) === '' ? null : trim($note),
                ]);
            } catch (UniqueConstraintViolationException $e) {
                // Two simultaneous submissions of the same form; resolve to the winner.
                $winner = Payment::query()->where('submission_key', $submissionKey)->first();

                if ($winner === null) {
                    throw $e;
                }

                return $winner;
            } catch (QueryException $e) {
                // The capacity trigger caught what the check above could not see in time.
                if (! str_contains($e->getMessage(), self::MOVEMENT_CAPACITY)) {
                    throw $e;
                }

                throw ValidationException::withMessages(['amount' => 'El movimiento no tiene importe libre suficiente: quedan '.Money::format($this->freeCentsOf((int) $bankMovementId)).' por conciliar.']);
            }
        });
    }

    /**
     * What is left of a movement: its amount minus the payments still active.
     */
    private function freeCentsOf(int $bankMovementId): int
    {
        $movement = BankMovement::query()->findOrFail($bankMovementId);

        $used = (int) Payment::query()
            ->where('bank_movement_id', $bankMovementId)
            ->whereNull('voided_at')
            ->sum('amount_cents');

        return max(0, $movement->amount_cents - $used);
    }
}
