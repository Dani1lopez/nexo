<?php

namespace App\Modules\Cash\Actions;

use App\Modules\Cash\Models\CashExpense;
use App\Modules\Cash\Models\CashOpeningBalance;
use App\Modules\Fees\Models\Payment;

/**
 * Read-only figure of the money the association keeps in the safe: the one-time opening balance plus the
 * active fee payments registered as cash, minus the confirmed cash expenses. An existing cash payment
 * already means money in the box, so it is never copied to another table, and the base excludes it for
 * the same reason: counting it in both places would count the same money twice. A voided payment keeps
 * its history but stops holding money, a bank payment never reaches this figure, and a voided expense
 * returns its money to the box, so the two balances stay apart.
 */
class BuildCashBalance
{
    /**
     * @return array{cashCents: int, cashFeeCents: int, cashPayments: int, openingCents: ?int, opening: ?CashOpeningBalance, expenseCents: int, expenseCount: int}
     */
    public function handle(): array
    {
        $active = Payment::query()
            ->where('method', Payment::CASH)
            ->whereNull('voided_at');

        // A draft does not move the figure; a confirmed expense does, until it is voided again.
        $confirmed = CashExpense::query()
            ->whereNotNull('confirmed_at')
            ->whereNull('voided_at');

        $feeCents = (int) (clone $active)->sum('amount_cents');
        $expenseCents = (int) (clone $confirmed)->sum('amount_cents');
        // Read once: the caller renders this very row, so the figure and the display can never disagree.
        $opening = CashOpeningBalance::query()->with('user')->first();
        $openingCents = $opening === null ? null : $opening->amount();

        return [
            'cashCents' => $feeCents + (int) $openingCents - $expenseCents,
            'cashFeeCents' => $feeCents,
            'cashPayments' => (clone $active)->count(),
            'openingCents' => $openingCents,
            'opening' => $opening,
            'expenseCents' => $expenseCents,
            'expenseCount' => (clone $confirmed)->count(),
        ];
    }
}
