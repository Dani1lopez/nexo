<?php

namespace App\Modules\Fees\Models;

use App\Modules\Bank\Models\BankMovement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    public const BANK = 'bank';

    public const CASH = 'cash';

    /** @var array<string, string> */
    public const METHODS = [self::BANK => 'Banco', self::CASH => 'Efectivo'];

    public function due(): BelongsTo
    {
        return $this->belongsTo(Due::class);
    }

    public function bankMovement(): BelongsTo
    {
        return $this->belongsTo(BankMovement::class);
    }

    public function isActive(): bool
    {
        return $this->voided_at === null;
    }

    protected function casts(): array
    {
        return ['paid_on' => 'date', 'voided_at' => 'datetime'];
    }
}
