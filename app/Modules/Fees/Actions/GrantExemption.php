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
            if ($due->activeExemption() !== null) {
                throw ValidationException::withMessages(['reason' => 'Esta cuota ya tiene una exención vigente.']);
            }

            return Exemption::forceCreate(['due_id' => $due->id, 'reason' => trim($reason)]);
        });
    }
}
