<?php

namespace App\Modules\Bank\Models;

use App\Modules\Access\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A complete imported charge that the association already paid: the statement moved the money, so
 * classifying it only explains what it was for. It never changes the bank balance and never reaches
 * the cash figure, because it is money that left the bank account and not the safe.
 */
class BankExpense extends Model
{
    public const MATERIAL = 'material';

    public const ACTIVITIES = 'activities';

    public const PREMISES = 'premises';

    public const OTHER = 'other';

    /** The predefined list, keyed by the value stored in the row. */
    public const CATEGORIES = [
        self::MATERIAL => 'Material',
        self::ACTIVITIES => 'Actividades',
        self::PREMISES => 'Alquiler y suministros',
        self::OTHER => 'Otros',
    ];

    public function bankMovement(): BelongsTo
    {
        return $this->belongsTo(BankMovement::class);
    }

    /** Who classified it; the void keeps the same author and adds the reason. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The absolute amount of the charge, so the figures never carry a second sign. */
    public function amount(): int
    {
        return (int) $this->amount_cents;
    }

    public function isActive(): bool
    {
        return $this->voided_at === null;
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? $this->category;
    }

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'voided_at' => 'datetime',
        ];
    }
}
