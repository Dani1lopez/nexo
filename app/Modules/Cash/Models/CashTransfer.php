<?php

namespace App\Modules\Cash\Models;

use App\Modules\Access\Models\User;
use App\Modules\Bank\Models\BankMovement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money moving between the bank account and the safe. The statement already moved it in the bank, so
 * the row only records the direction and moves the cash figure: a charge of the statement funded the
 * safe, a credit took cash to the bank. No transfer is ever an income or an expense.
 */
class CashTransfer extends Model
{
    public const TO_CASH = 'to_cash';

    public const TO_BANK = 'to_bank';

    public function bankMovement(): BelongsTo
    {
        return $this->belongsTo(BankMovement::class);
    }

    /** Who marked it; the void keeps the same author and adds the reason. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function amount(): int
    {
        return (int) $this->amount_cents;
    }

    public function isActive(): bool
    {
        return $this->voided_at === null;
    }

    /** Cash grows when the money arrives from the bank and shrinks when it goes back. */
    public function signedCents(): int
    {
        return $this->direction === self::TO_CASH ? $this->amount() : -$this->amount();
    }

    public function directionLabel(): string
    {
        return $this->direction === self::TO_CASH ? 'Del banco a la caja' : 'De la caja al banco';
    }

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'voided_at' => 'datetime',
        ];
    }
}
