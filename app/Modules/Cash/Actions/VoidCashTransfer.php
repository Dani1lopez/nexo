<?php

namespace App\Modules\Cash\Actions;

use App\Modules\Cash\Models\CashTransfer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Voiding a transfer keeps it and its reason in the history, frees the movement again and takes its
 * effect out of the cash figure. The bank balance never moves: the statement always counted it.
 */
class VoidCashTransfer
{
    public function handle(CashTransfer $transfer, string $reason): CashTransfer
    {
        return DB::transaction(function () use ($transfer, $reason): CashTransfer {
            if ($transfer->fresh()->voided_at !== null) {
                throw ValidationException::withMessages(['reason' => 'Este traspaso ya estaba anulado.']);
            }

            $transfer->forceFill(['voided_at' => now(), 'void_reason' => trim($reason)])->save();

            return $transfer;
        });
    }
}
