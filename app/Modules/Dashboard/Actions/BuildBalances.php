<?php

namespace App\Modules\Dashboard\Actions;

use App\Modules\Bank\Models\BankExpense;
use App\Modules\Bank\Models\BankImport;
use App\Modules\Bank\Models\BankMovement;
use App\Modules\Cash\Actions\BuildCashBalance;
use App\Modules\Fees\Actions\SumDues;
use App\Modules\Fees\Models\Due;
use App\Modules\Fees\Models\FeeYear;
use Illuminate\Support\Carbon;

/**
 * Read-only figures for the balances screen: the balance of the imported statement, the money in the
 * safe, the credits still to reconcile and the debt broken down by fee year.
 *
 * Dues are summed with their own rules (exemptions, voided payments), never re-derived here, and the
 * cash keeps its own source: the active cash payments. Both the credits and the dues are read in
 * chunks, so the screen never loads the whole statement or every due of every year at once.
 */
class BuildBalances
{
    private const CHUNK = 200;

    public function __construct(private SumDues $sumDues, private BuildCashBalance $cashBalance) {}

    /**
     * @return array{
     *     balanceCents: int,
     *     movements: int,
     *     cashCents: int,
     *     cashPayments: int,
     *     unreconciledCents: int,
     *     unreconciledCount: int,
     *     otherIncomeCents: int,
     *     otherIncomeCount: int,
     *     bankExpenseCents: int,
     *     bankExpenseCount: int,
     *     lastImportAt: ?Carbon,
     *     years: list<array{id: int, year: int, payableCents: int, paidCents: int, pendingCents: int, pendingDues: int}>,
     *     totalPendingCents: int,
     *     totalPendingDues: int
     * }
     */
    public function handle(): array
    {
        // Only credits need a destination, and a credit used in part or classified as other income
        // still has a remainder or none at all: the figures below are its active payments and its
        // active classification.
        $unreconciledCents = 0;
        $unreconciledCount = 0;
        $otherIncomeCents = 0;
        $otherIncomeCount = 0;
        $bankExpenseCents = 0;
        $bankExpenseCount = 0;

        BankMovement::query()
            ->where('amount_cents', '>', 0)
            ->with([
                'payments' => fn ($query) => $query->whereNull('voided_at'),
                'otherIncomes' => fn ($query) => $query->whereNull('voided_at'),
            ])
            ->chunkById(self::CHUNK, function ($credits) use (&$unreconciledCents, &$unreconciledCount, &$otherIncomeCents, &$otherIncomeCount): void {
                foreach ($credits as $movement) {
                    $remainder = max(0, $movement->amount_cents
                        - (int) $movement->payments->sum('amount_cents')
                        - (int) $movement->otherIncomes->sum('amount_cents'));

                    if ($remainder > 0) {
                        $unreconciledCount++;
                    }

                    $unreconciledCents += $remainder;

                    foreach ($movement->otherIncomes as $income) {
                        $otherIncomeCount++;
                        $otherIncomeCents += (int) $income->amount_cents;
                    }
                }
            });

        // Classified charges explain what the money was spent on. The statement already subtracted
        // them, so this figure is informational and never enters the bank balance.
        BankExpense::query()
            ->whereNull('voided_at')
            ->chunkById(self::CHUNK, function ($expenses) use (&$bankExpenseCents, &$bankExpenseCount): void {
                foreach ($expenses as $expense) {
                    $bankExpenseCount++;
                    $bankExpenseCents += $expense->amount();
                }
            });

        $years = FeeYear::query()
            ->orderByDesc('year')
            ->get()
            ->map(fn (FeeYear $feeYear): array => [
                'id' => (int) $feeYear->id,
                'year' => (int) $feeYear->year,
                ...$this->sumDues->handle(Due::query()->where('fee_year_id', $feeYear->id)),
            ])
            ->all();

        return [
            'balanceCents' => (int) BankMovement::query()->sum('amount_cents'),
            'movements' => BankMovement::query()->count(),
            'unreconciledCents' => $unreconciledCents,
            'unreconciledCount' => $unreconciledCount,
            'otherIncomeCents' => $otherIncomeCents,
            'otherIncomeCount' => $otherIncomeCount,
            'bankExpenseCents' => $bankExpenseCents,
            'bankExpenseCount' => $bankExpenseCount,
            'lastImportAt' => BankImport::query()->latest('id')->value('created_at'),
            ...$this->cashBalance->handle(),
            'years' => $years,
            'totalPendingCents' => (int) array_sum(array_column($years, 'pendingCents')),
            'totalPendingDues' => (int) array_sum(array_column($years, 'pendingDues')),
        ];
    }
}
