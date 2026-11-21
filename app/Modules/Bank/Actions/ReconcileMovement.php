<?php

namespace App\Modules\Bank\Actions;

use App\Modules\Bank\Models\BankMovement;
use App\Modules\Bank\Models\OtherIncome;
use App\Modules\Fees\Actions\RegisterPayment;
use App\Modules\Fees\Models\Due;
use App\Modules\Fees\Models\Payment;
use App\Modules\Fees\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

class ReconcileMovement
{
    public function __construct(private RegisterPayment $payments) {}

    /**
     * Split one credit movement into the payments the person chose.
     *
     * Since #71 the split no longer has to use the whole credit: it may take less than what is
     * free and the rest stays pending. It never exceeds the free amount of the movement nor what
     * each chosen due still owes.
     *
     * @param  array<int, int>  $allocations  due id => amount in cents
     * @return list<Payment>
     *
     * @throws ValidationException
     */
    public function handle(BankMovement $movement, array $allocations, string $submissionKey): array
    {
        if ($movement->amount_cents <= 0) {
            throw ValidationException::withMessages([
                'allocations' => 'Solo se pueden conciliar abonos. Un cargo no genera un pago.',
            ]);
        }

        if ($allocations === []) {
            throw ValidationException::withMessages([
                'allocations' => 'Indica el importe de al menos una cuota.',
            ]);
        }

        // One deterministic key per line: resending the same form finds its payments instead of creating new ones.
        $keys = [];
        foreach (array_keys($allocations) as $dueId) {
            $keys[$dueId] = Uuid::uuid5($submissionKey, (string) $dueId)->toString();
        }

        $replayed = $this->replayed($movement, $keys, $allocations);
        if ($replayed !== null) {
            return $replayed;
        }

        $dues = $this->dues(array_keys($allocations));

        // All or nothing: a guard refusing one line undoes the whole split.
        return DB::transaction(function () use ($dues, $allocations, $keys, $movement): array {
            // The whole credit already went to the other destination, so this money is not free.
            $this->refuseIfClassifiedElsewhere($movement);

            // Read inside the transaction: a concurrent void must not leave the free amount stale.
            $free = $this->refuseUnlessAllocationsFit($movement, $allocations);
            $this->refuseDuesAlreadyPaidBy($movement, array_keys($allocations));

            $created = [];

            foreach ($allocations as $dueId => $cents) {
                // The existing payment rules decide each line: exempt dues and overpayments are refused there.
                $created[] = $this->payments->handle(
                    $dues[$dueId],
                    $cents,
                    $movement->occurred_on->toDateString(),
                    Payment::BANK,
                    'Conciliado desde el extracto: '.$movement->concept,
                    $keys[$dueId],
                    $movement->id,
                );
            }

            // Post-condition: this split used exactly the free amount it read. A void committed
            // between the check above and these inserts would leave a different remainder behind,
            // and the capacity trigger only enforces an upper bound, so the end state is verified
            // here and the whole split rolls back when it does not hold. The money left over by a
            // split that took less than what was free stays pending, as #71 asks.
            $left = $this->remainingCents($movement);

            if ($left !== $free - array_sum($allocations)) {
                throw ValidationException::withMessages([
                    'allocations' => 'El movimiento ha cambiado mientras se repartía: quedan '.Money::format($left).' por conciliar. Vuelve a intentarlo.',
                ]);
            }

            return $created;
        });
    }

    /**
     * The payments an earlier submission of the same form already created, or null when this is a new split.
     *
     * A key identifies one payload, so a resend carrying other lines, other amounts or another
     * movement is refused instead of being honoured as a fresh split: that would change the
     * previous split silently.
     *
     * @param  array<int, string>  $keys  due id => submission key
     * @param  array<int, int>  $allocations  due id => amount in cents
     * @return list<Payment>|null
     *
     * @throws ValidationException
     */
    private function replayed(BankMovement $movement, array $keys, array $allocations): ?array
    {
        $found = Payment::query()
            ->whereIn('submission_key', array_values($keys))
            ->orderBy('id')
            ->get()
            ->keyBy('submission_key');

        if ($found->isEmpty()) {
            return null;
        }

        // A key belongs to the movement whose form rendered it: reusing it elsewhere is a stale form.
        if ($found->contains(fn (Payment $payment): bool => $payment->bank_movement_id !== $movement->id)) {
            throw ValidationException::withMessages([
                'submission_key' => 'El formulario ha caducado. Recarga la página e inténtalo de nuevo.',
            ]);
        }

        // A voided payment still holds its submission key, so this form can no longer be honoured:
        // replaying it would announce a reconciliation that no longer exists, and retrying it as a
        // new split would collide with the unique key of the voided row. Reject it as expired.
        if ($found->contains(fn (Payment $payment): bool => ! $payment->isActive())) {
            throw ValidationException::withMessages([
                'submission_key' => 'El formulario ha caducado: anulaste un pago de este reparto. Recarga la página y concílialo de nuevo.',
            ]);
        }

        $payments = [];

        foreach ($keys as $dueId => $key) {
            /** @var Payment|null $payment */
            $payment = $found->get($key);

            // The same key with other lines or other amounts is a changed form: the stored split
            // keeps what it has, and the mismatch is announced instead of confirmed as a success.
            if ($payment === null || (int) $payment->amount_cents !== $allocations[$dueId]) {
                throw ValidationException::withMessages([
                    'submission_key' => 'El formulario enviado no coincide con el reparto ya guardado. Recarga la página e inténtalo de nuevo.',
                ]);
            }

            $payments[] = $payment;
        }

        return $payments;
    }

    /**
     * A line whose due this very movement already pays would be dropped by the payment guard,
     * so the money would be announced and left unassigned: refuse the whole split instead.
     * A replay of the same form never reaches this point, it returns before the transaction.
     *
     * @param  list<int>  $dueIds
     *
     * @throws ValidationException
     */
    private function refuseDuesAlreadyPaidBy(BankMovement $movement, array $dueIds): void
    {
        $alreadyPaid = Payment::query()
            ->where('bank_movement_id', $movement->id)
            ->whereIn('due_id', $dueIds)
            ->whereNull('voided_at')
            ->exists();

        if ($alreadyPaid) {
            throw ValidationException::withMessages([
                'allocations' => 'Una de las cuotas elegidas ya tiene un pago vigente de este movimiento. Anula ese pago antes de repartirlo de nuevo o elige otra cuota.',
            ]);
        }
    }

    /**
     * The other destination of the same money: a credit already classified as other income cannot
     * also pay dues. Voiding the classification is what frees it again.
     *
     * @throws ValidationException
     */
    private function refuseIfClassifiedElsewhere(BankMovement $movement): void
    {
        $classified = OtherIncome::query()
            ->where('bank_movement_id', $movement->id)
            ->whereNull('voided_at')
            ->exists();

        if ($classified) {
            throw ValidationException::withMessages([
                'allocations' => 'El abono está clasificado como otro ingreso. Anula esa clasificación antes de asignarlo a cuotas.',
            ]);
        }
    }

    /**
     * The split must be a positive amount that never exceeds what is still free. Since #71 it no
     * longer has to use the whole credit: whatever is left stays pending.
     *
     * The free amount is read inside the transaction so a concurrent void cannot leave it stale
     * and let this split take more than the movement holds.
     *
     * @param  array<int, int>  $allocations
     * @return int the free amount this split was measured against
     *
     * @throws ValidationException
     */
    private function refuseUnlessAllocationsFit(BankMovement $movement, array $allocations): int
    {
        $remaining = $this->remainingCents($movement);
        $allocated = array_sum($allocations);

        if ($allocated > 0 && $allocated <= $remaining) {
            return $remaining;
        }

        throw ValidationException::withMessages([
            'allocations' => $remaining === 0
                ? 'El movimiento ya está conciliado por completo. Anula el pago que sobra antes de repartirlo de nuevo.'
                : 'El reparto suma '.Money::format($allocated).' y quedan '.Money::format($remaining).' por conciliar en este movimiento. Ajusta los importes.',
        ]);
    }

    /**
     * What is left to reconcile: the movement amount minus its active payments.
     */
    private function remainingCents(BankMovement $movement): int
    {
        $used = (int) $movement->payments()->whereNull('voided_at')->sum('amount_cents');

        return max(0, $movement->amount_cents - $used);
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, Due>
     *
     * @throws ValidationException
     */
    private function dues(array $ids): array
    {
        $dues = Due::query()->whereIn('id', $ids)->get();

        if ($dues->count() !== count($ids)) {
            throw ValidationException::withMessages([
                'allocations' => 'Una de las cuotas elegidas ya no existe. Recarga la página e inténtalo de nuevo.',
            ]);
        }

        $map = [];
        foreach ($dues as $due) {
            $map[$due->id] = $due;
        }

        return $map;
    }
}
