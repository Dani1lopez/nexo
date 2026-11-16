<?php

namespace App\Modules\Fees\Models;

use App\Modules\Members\Models\Member;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Due extends Model
{
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function feeYear(): BelongsTo
    {
        return $this->belongsTo(FeeYear::class);
    }

    public function exemptions(): HasMany
    {
        return $this->hasMany(Exemption::class)->orderBy('id');
    }

    public function activeExemption(): ?Exemption
    {
        return $this->exemptions()->whereNull('revoked_at')->first();
    }

    /** The amount still to be paid: zero while an exemption is in force. */
    public function payableCents(): int
    {
        return $this->activeExemption() === null ? $this->feeYear->amount_cents : 0;
    }
}
