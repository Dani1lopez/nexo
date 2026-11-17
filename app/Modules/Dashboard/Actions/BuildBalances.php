<?php

namespace App\Modules\Dashboard\Actions;

use App\Modules\Bank\Models\BankImport;
use App\Modules\Bank\Models\BankMovement;
use App\Modules\Fees\Models\Due;
use App\Modules\Fees\Models\FeeYear;
use Illuminate\Support\Carbon;

/**
 * Read-only figures for the balances screen: the balance of the imported statement, the credits still
 * to reconcile and the debt broken down by fee year.
 *
 * Dues are summed with their own rules (exemptions, voided payments), never re-derived here. Like
 * BuildOverview, it loads the dues once and sums them in PHP, which keeps the rules in one place and
 * is fine at the size of an association.
 */
class BuildBalances
{
    /**
     * @return array{
     *     balanceCents: int,
     *     movements: int,
     *     unreconciledCents: int,
     *     unreconciledCount: int,
     *     lastImportAt: ?Carbon,
     *     years: list<array{id: int, year: int, payableCents: int, paidCents: int, pendingCents: int, pendingDues: int}>,
     *     totalPendingCents: int,
     *     totalPendingDues: int
     * }
     */
    public function handle(): array
    {
        // Only credits can feed a payment, and a credit reconciled in part still has a remainder.
        $remainders = BankMovement::query()
            ->where('amount_cents', '>', 0)
            ->with(['payments' => fn ($query) => $query->whereNull('voided_at')])
            ->get()
            ->map(fn (BankMovement $movement): int => max(0, $movement->amount_cents - (int) $movement->payments->sum('amount_cents')));

        $years = FeeYear::query()
            ->with(['dues.exemptions', 'dues.payments'])
            ->orderByDesc('year')
            ->get()
            ->map(fn (FeeYear $feeYear): array => $this->yearRow($feeYear))
            ->all();

        return [
            'balanceCents' => (int) BankMovement::query()->sum('amount_cents'),
            'movements' => BankMovement::query()->count(),
            'unreconciledCents' => (int) $remainders->sum(),
            'unreconciledCount' => $remainders->filter(fn (int $cents): bool => $cents > 0)->count(),
            'lastImportAt' => BankImport::query()->latest('id')->value('created_at'),
            'years' => $years,
            'totalPendingCents' => (int) array_sum(array_column($years, 'pendingCents')),
            'totalPendingDues' => (int) array_sum(array_column($years, 'pendingDues')),
        ];
    }

    /**
     * @return array{id: int, year: int, payableCents: int, paidCents: int, pendingCents: int, pendingDues: int}
     */
    private function yearRow(FeeYear $feeYear): array
    {
        // The inverse relation is set explicitly: the dues were loaded through the year, not the other way round.
        $dues = $feeYear->dues->each(fn (Due $due) => $due->setRelation('feeYear', $feeYear));

        return [
            'id' => (int) $feeYear->id,
            'year' => (int) $feeYear->year,
            'payableCents' => (int) $dues->sum(fn (Due $due): int => $due->payableCents()),
            'paidCents' => (int) $dues->sum(fn (Due $due): int => $due->paidCents()),
            'pendingCents' => (int) $dues->sum(fn (Due $due): int => $due->pendingCents()),
            'pendingDues' => $dues->filter(fn (Due $due): bool => $due->pendingCents() > 0)->count(),
        ];
    }
}
