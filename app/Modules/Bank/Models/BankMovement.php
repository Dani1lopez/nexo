<?php

namespace App\Modules\Bank\Models;

use App\Modules\Fees\Models\Payment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankMovement extends Model
{
    /**
     * Identity of a movement for re-import detection: date, amount and concept,
     * with the concept compared ignoring case and extra whitespace.
     */
    public static function fingerprint(string $date, int $cents, string $concept): string
    {
        $normalized = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $concept)));

        return hash('sha256', $date.'|'.$cents.'|'.$normalized);
    }

    public function bankImport(): BelongsTo
    {
        return $this->belongsTo(BankImport::class);
    }

    /**
     * Payments created from this movement. Since #34 it can feed several at once: its capacity
     * is its own amount, so the sum of the active ones never exceeds it.
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Classifications of this credit as other income. Since #44 at most one is active, and never
     * together with an active payment: the whole credit goes to one destination.
     */
    public function otherIncomes(): HasMany
    {
        return $this->hasMany(OtherIncome::class);
    }

    protected function casts(): array
    {
        return ['occurred_on' => 'date'];
    }
}
