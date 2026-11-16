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

    /** Uses the loaded relation when present, so listings do not repeat queries. */
    public function activeExemption(): ?Exemption
    {
        return $this->exemptions->first(fn (Exemption $exemption): bool => $exemption->revoked_at === null);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('id');
    }

    /** The amount still to be paid: zero while an exemption is in force. */
    public function payableCents(): int
    {
        return $this->activeExemption() === null ? $this->feeYear->amount_cents : 0;
    }

    /** Sum of the payments that have not been voided. */
    public function paidCents(): int
    {
        return (int) $this->payments->filter(fn (Payment $payment): bool => $payment->isActive())->sum('amount_cents');
    }

    public function pendingCents(): int
    {
        return max(0, $this->payableCents() - $this->paidCents());
    }

    /** One of pending, partial, paid or exempt. */
    public function status(): string
    {
        if ($this->activeExemption() !== null) {
            return 'exempt';
        }

        return match (true) {
            $this->paidCents() === 0 => 'pending',
            $this->pendingCents() === 0 => 'paid',
            default => 'partial',
        };
    }
}
