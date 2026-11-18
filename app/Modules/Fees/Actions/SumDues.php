<?php

namespace App\Modules\Fees\Actions;

use App\Modules\Fees\Models\Due;
use Illuminate\Database\Eloquent\Builder;

/**
 * Totals for a set of dues, accumulated in chunks so a large association never loads
 * every due at once. The per-due rules stay in Due and are never re-derived here.
 */
final class SumDues
{
    private const CHUNK = 200;

    /**
     * @param  Builder<Due>  $dues
     * @return array{payableCents: int, paidCents: int, pendingCents: int, pendingDues: int}
     */
    public function handle(Builder $dues): array
    {
        $totals = ['payableCents' => 0, 'paidCents' => 0, 'pendingCents' => 0, 'pendingDues' => 0];

        $dues->with(['feeYear', 'exemptions', 'payments'])
            ->chunkById(self::CHUNK, function ($chunk) use (&$totals): void {
                foreach ($chunk as $due) {
                    $pending = $due->pendingCents();
                    $totals['payableCents'] += $due->payableCents();
                    $totals['paidCents'] += $due->paidCents();
                    $totals['pendingCents'] += $pending;
                    $totals['pendingDues'] += $pending > 0 ? 1 : 0;
                }
            });

        return $totals;
    }
}
