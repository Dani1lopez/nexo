<?php

namespace App\Modules\Fees\Actions;

use App\Modules\Fees\Models\Due;
use App\Modules\Fees\Models\Exemption;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GrantExemption
{
    public function handle(Due $due, string $reason): Exemption
    {
        return DB::transaction(function () use ($due, $reason): Exemption {
            // Reload inside the transaction: previously loaded relations may be stale.
            $due->load(['exemptions', 'payments']);

            if ($due->paidCents() > 0) {
                throw ValidationException::withMessages(['reason' => 'No se puede conceder la exención: la cuota tiene pagos vigentes. Anúlalos antes.']);
            }

            if ($due->activeExemption() !== null) {
                throw ValidationException::withMessages(['reason' => 'Esta cuota ya tiene una exención vigente.']);
            }

            return Exemption::forceCreate(['due_id' => $due->id, 'reason' => trim($reason)]);
        });
    }
}
