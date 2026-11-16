<?php

namespace App\Modules\Dashboard\Actions;

use App\Modules\Bank\Models\BankImport;
use App\Modules\Bank\Models\BankMovement;
use App\Modules\Fees\Models\FeeYear;
use App\Modules\Members\Models\Member;
use Illuminate\Support\Carbon;

/**
 * Read-only figures for the dashboard: active members, the money of the latest fee year and the bank import.
 * Dues are summed with their own rules (exemptions, voided payments), never re-derived here.
 *
 * Known limit: it loads the dues of the latest year once and sums them in PHP, which keeps the rules in one
 * place and is fine at the size of an association. If the volume ever grows, move only the sums to SQL.
 */
class BuildOverview
{
    /**
     * @return array{activeMembers: int, year: ?int, payableCents: int, paidCents: int, pendingCents: int, pendingDues: int, movements: int, lastImportAt: ?Carbon}
     */
    public function handle(): array
    {
        $feeYear = FeeYear::query()->orderByDesc('year')->first();
        $dues = $feeYear
            ? $feeYear->dues()->with(['exemptions', 'payments'])->get()->each(fn ($due) => $due->setRelation('feeYear', $feeYear))
            : collect();

        return [
            'activeMembers' => Member::query()->where('status', Member::ACTIVE)->count(),
            'year' => $feeYear?->year,
            'payableCents' => $dues->sum(fn ($due) => $due->payableCents()),
            'paidCents' => $dues->sum(fn ($due) => $due->paidCents()),
            'pendingCents' => $dues->sum(fn ($due) => $due->pendingCents()),
            'pendingDues' => $dues->filter(fn ($due) => $due->pendingCents() > 0)->count(),
            'movements' => BankMovement::query()->count(),
            'lastImportAt' => BankImport::query()->latest('id')->value('created_at'),
        ];
    }
}
