<?php

namespace App\Modules\Cash\Actions;

use App\Modules\Cash\Models\CashOpeningBalance;
use App\Modules\Fees\Models\Payment;

/**
 * Read-only figure of the money the association keeps in the safe: the one-time opening balance plus the
 * active fee payments registered as cash. An existing cash payment already means money in the box, so it
 * is never copied to another table, and the base excludes it for the same reason: counting it in both
 * places would count the same money twice. A voided payment keeps its history but stops holding money,
 * and a bank payment never reaches this figure, so the two balances stay apart.
 */
class BuildCashBalance
{
    /**
     * @return array{cashCents: int, cashFeeCents: int, cashPayments: int, openingCents: ?int}
     */
    public function handle(): array
    {
        $active = Payment::query()
            ->where('method', Payment::CASH)
            ->whereNull('voided_at');

        $feeCents = (int) (clone $active)->sum('amount_cents');
        $opening = CashOpeningBalance::query()->first();
        $openingCents = $opening === null ? null : $opening->amount();

        return [
            'cashCents' => $feeCents + (int) $openingCents,
            'cashFeeCents' => $feeCents,
            'cashPayments' => (clone $active)->count(),
            'openingCents' => $openingCents,
        ];
    }
}
