<?php

namespace App\Modules\Cash\Models;

use App\Modules\Access\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The money the association already had in the safe when it started using Nexo. It is a one-time base
 * that excludes every cash fee payment registered here: those are added by BuildCashBalance, so the
 * same money is never counted twice. Zero is a real base and not the same as no base at all.
 */
class CashOpeningBalance extends Model
{
    /** Who fixed the base; the row is never rewritten, so it is also its only author. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function amount(): int
    {
        return (int) $this->amount_cents;
    }

    public function fixedOn(): Carbon
    {
        return $this->created_at;
    }

    protected function casts(): array
    {
        return ['amount_cents' => 'integer', 'created_at' => 'datetime', 'updated_at' => 'datetime'];
    }
}
