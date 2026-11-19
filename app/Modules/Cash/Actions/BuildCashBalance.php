<?php

namespace App\Modules\Cash\Actions;

use App\Modules\Fees\Models\Payment;

/**
 * Read-only figure of the money the association keeps in the safe. In this version it is exactly the
 * active fee payments registered as cash: an existing cash payment already means money in the box, so
 * it is never copied to another table. A voided payment keeps its history but stops holding money, and
 * a bank payment never reaches this figure, so the two balances stay apart.
 */
class BuildCashBalance
{
    /**
     * @return array{cashCents: int, cashPayments: int}
     */
    public function handle(): array
    {
        $active = Payment::query()
            ->where('method', Payment::CASH)
            ->whereNull('voided_at');

        return [
            'cashCents' => (int) (clone $active)->sum('amount_cents'),
            'cashPayments' => (clone $active)->count(),
        ];
    }
}
