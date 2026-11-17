<?php

namespace App\Modules\Bank\Actions;

use App\Modules\Bank\Models\BankMovement;
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

        $replayed = $this->replayed($keys);
        if ($replayed !== null) {
            return $replayed;
        }

        $remaining = $this->remainingCents($movement);
        $allocated = array_sum($allocations);

        if ($allocated !== $remaining) {
            throw ValidationException::withMessages([
                'allocations' => $remaining === 0
                    ? 'El movimiento ya está conciliado por completo. Anula el pago que sobra antes de repartirlo de nuevo.'
                    : 'El reparto suma '.Money::format($allocated).' y quedan '.Money::format($remaining).' por conciliar en este movimiento. Ajusta los importes.',
            ]);
        }

        $dues = $this->dues(array_keys($allocations));

        // All or nothing: a guard refusing one line undoes the whole split.
        return DB::transaction(function () use ($dues, $allocations, $keys, $movement): array {
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

            return $created;
        });
    }

    /**
     * The payments an earlier submission of the same form already created, or null when this is a new split.
     *
     * @param  array<int, string>  $keys  due id => submission key
     * @return list<Payment>|null
     */
    private function replayed(array $keys): ?array
    {
        $found = Payment::query()
            ->whereIn('submission_key', array_values($keys))
            ->orderBy('id')
            ->get()
            ->keyBy('submission_key');

        if ($found->count() !== count($keys)) {
            return null;
        }

        $payments = [];
        foreach ($keys as $key) {
            /** @var Payment $payment */
            $payment = $found->get($key);
            $payments[] = $payment;
        }

        return $payments;
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
