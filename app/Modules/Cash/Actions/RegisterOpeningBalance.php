<?php

namespace App\Modules\Cash\Actions;

use App\Modules\Cash\Models\CashOpeningBalance;
use App\Modules\Fees\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Fixes the one-time cash base: the money already in the safe, excluding every cash fee payment Nexo
 * registers, because BuildCashBalance adds those.
 *
 * The base is stored once. Repeating the same amount is harmless, so a double submit or a reloaded form
 * changes nothing, and another amount never overwrites the fixed base: it is the starting point the
 * recorded cash history rests on, not a figure to recalculate. The database holds the single row too
 * (see migration 2026_01_01_000013), because two simultaneous first submissions can pass the read below.
 */
class RegisterOpeningBalance
{
    /**
     * @throws ValidationException
     */
    public function handle(int $amountCents, int $userId): CashOpeningBalance
    {
        if ($amountCents < 0) {
            throw ValidationException::withMessages([
                'amount' => 'El saldo inicial no puede ser negativo.',
            ]);
        }

        $existing = CashOpeningBalance::query()->first();

        if ($existing !== null) {
            return $this->resolve($existing, $amountCents);
        }

        try {
            return DB::transaction(fn (): CashOpeningBalance => CashOpeningBalance::forceCreate([
                'user_id' => $userId,
                'amount_cents' => $amountCents,
            ]));
        } catch (QueryException $e) {
            // The unique index let exactly one simultaneous submission in. Same amount is the same
            // base and stays harmless; a different amount cannot overwrite the row that won.
            $winner = CashOpeningBalance::query()->first();

            if ($winner === null) {
                throw $e;
            }

            return $this->resolve($winner, $amountCents);
        }
    }

    /**
     * @throws ValidationException
     */
    private function resolve(CashOpeningBalance $opening, int $amountCents): CashOpeningBalance
    {
        if ($opening->amount() === $amountCents) {
            return $opening;
        }

        throw ValidationException::withMessages([
            'amount' => 'El saldo inicial ya está fijado en '.Money::format($opening->amount())
                .' y no se sobrescribe. Es una base única que no se recalcula.',
        ]);
    }
}
