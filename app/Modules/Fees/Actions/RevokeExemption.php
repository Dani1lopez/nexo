<?php

namespace App\Modules\Fees\Actions;

use App\Modules\Fees\Models\Exemption;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Revoking keeps the exemption and its original reason in the history.
 */
class RevokeExemption
{
    public function handle(Exemption $exemption, string $reason): Exemption
    {
        return DB::transaction(function () use ($exemption, $reason): Exemption {
            if ($exemption->fresh()->revoked_at !== null) {
                throw ValidationException::withMessages(['reason' => 'Esta exención ya estaba anulada.']);
            }

            $exemption->forceFill(['revoked_at' => now(), 'revoked_reason' => trim($reason)])->save();

            return $exemption;
        });
    }
}
