<?php

namespace App\Modules\Bank\Actions;

use App\Modules\Bank\Models\OtherIncome;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Voiding a classification keeps it and its reason in the history and frees the credit again,
 * so it can pay a due or be classified once more.
 */
class VoidOtherIncome
{
    public function handle(OtherIncome $income, string $reason): OtherIncome
    {
        return DB::transaction(function () use ($income, $reason): OtherIncome {
            if ($income->fresh()->voided_at !== null) {
                throw ValidationException::withMessages(['reason' => 'Este ingreso ya estaba anulado.']);
            }

            $income->forceFill(['voided_at' => now(), 'void_reason' => trim($reason)])->save();

            return $income;
        });
    }
}
