<?php

namespace App\Modules\Bank\Models;

use App\Modules\Access\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A complete imported credit that is not money for the dues: it belongs to the association as
 * donations, activities, grants or anything else. Classification never moves the bank balance.
 */
class OtherIncome extends Model
{
    public const DONATIONS = 'donations';

    public const ACTIVITIES = 'activities';

    public const GRANTS = 'grants';

    public const OTHER = 'other';

    /** The predefined list, keyed by the value stored in the row. */
    public const CATEGORIES = [
        self::DONATIONS => 'Donaciones',
        self::ACTIVITIES => 'Actividades',
        self::GRANTS => 'Subvenciones',
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
        return ['voided_at' => 'datetime'];
    }
}
