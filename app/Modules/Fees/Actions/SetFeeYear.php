<?php

namespace App\Modules\Fees\Actions;

use App\Modules\Fees\Models\FeeYear;
use Illuminate\Support\Facades\DB;

class SetFeeYear
{
    public function __construct(private GenerateDues $dues) {}

    public function handle(int $year, int $amountCents): FeeYear
    {
        return DB::transaction(function () use ($year, $amountCents): FeeYear {
            $feeYear = FeeYear::create(['year' => $year, 'amount_cents' => $amountCents]);
            $this->dues->forYear($feeYear);

            return $feeYear;
        });
    }
}
