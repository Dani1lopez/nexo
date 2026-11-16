<?php

namespace App\Modules\Fees\Actions;

use App\Modules\Fees\Models\FeeYear;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateFeeAmount
{
    public function handle(FeeYear $feeYear, int $amountCents): FeeYear
    {
        return DB::transaction(function () use ($feeYear, $amountCents): FeeYear {
            if ($feeYear->hasActivePayments()) {
                throw ValidationException::withMessages(['amount' => 'No se puede cambiar el importe: hay pagos vigentes en este año. Anúlalos antes.']);
            }

            $feeYear->update(['amount_cents' => $amountCents]);

            return $feeYear;
        });
    }
}
