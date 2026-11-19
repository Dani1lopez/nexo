<?php

namespace App\Modules\Cash\Models;

use App\Modules\Access\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An expense paid with the money of the safe. It is born as a draft, which can still be edited;
 * confirming it subtracts its amount from the cash figure once, and the only correction of a
 * confirmed expense is a void with a reason, which returns the money and keeps the row forever.
 * Without a receipt the reason is mandatory, so a missing justification is always explained.
 */
class CashExpense extends Model
{
    public const CATEGORIES = [
        'material' => 'Material',
        'actividades' => 'Actividades',
        'alquiler' => 'Alquiler y suministros',
        'otros' => 'Otros',
    ];

    /** Who registered the draft. A void keeps the author: the history belongs to who wrote it. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isDraft(): bool
    {
        return $this->confirmed_at === null && $this->voided_at === null;
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null && $this->voided_at === null;
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? $this->category;
    }

    /**
     * Fingerprint of a registration payload as it was first submitted. The row is editable
     * afterwards, so a repeated submit compares against this, never against the current values.
     *
     * @throws \InvalidArgumentException
     */
    public static function submissionFingerprint(
        int $amountCents,
        string $spentOn,
        string $category,
        string $concept,
        bool $hasReceipt,
        ?string $reason,
    ): string {
        return hash('sha256', implode('|', [
            $amountCents,
            $spentOn,
            $category,
            $concept,
            $hasReceipt ? '1' : '0',
            trim((string) $reason),
        ]));
    }

    /**
     * @return 'draft'|'confirmed'|'voided'
     */
    public function state(): string
    {
        if ($this->isVoided()) {
            return 'voided';
        }

        return $this->isConfirmed() ? 'confirmed' : 'draft';
    }

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'spent_on' => 'date',
            'has_receipt' => 'boolean',
            'confirmed_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }
}
