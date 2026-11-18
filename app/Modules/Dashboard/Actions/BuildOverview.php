<?php

namespace App\Modules\Dashboard\Actions;

use App\Modules\Bank\Models\BankImport;
use App\Modules\Bank\Models\BankMovement;
use App\Modules\Fees\Actions\SumDues;
use App\Modules\Fees\Models\Due;
use App\Modules\Fees\Models\FeeYear;
use App\Modules\Members\Models\Member;
use Illuminate\Support\Carbon;

/**
 * Read-only figures for the dashboard: active members, the money of the latest fee year and the bank import.
 * Dues are summed with their own rules (exemptions, voided payments), never re-derived here, and SumDues
 * accumulates them in chunks so a large year never loads every due at once.
 */
class BuildOverview
{
    public function __construct(private SumDues $sumDues) {}

    /**
     * @return array{activeMembers: int, year: ?int, payableCents: int, paidCents: int, pendingCents: int, pendingDues: int, movements: int, lastImportAt: ?Carbon}
     */
    public function handle(): array
    {
        $feeYear = FeeYear::query()->orderByDesc('year')->first();
        $totals = $feeYear === null
            ? ['payableCents' => 0, 'paidCents' => 0, 'pendingCents' => 0, 'pendingDues' => 0]
            : $this->sumDues->handle(Due::query()->where('fee_year_id', $feeYear->id));

        return [
            'activeMembers' => Member::query()->where('status', Member::ACTIVE)->count(),
            'year' => $feeYear?->year,
            'payableCents' => $totals['payableCents'],
            'paidCents' => $totals['paidCents'],
            'pendingCents' => $totals['pendingCents'],
            'pendingDues' => $totals['pendingDues'],
            'movements' => BankMovement::query()->count(),
            'lastImportAt' => BankImport::query()->latest('id')->value('created_at'),
        ];
    }
}
