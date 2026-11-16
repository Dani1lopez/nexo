<?php

namespace App\Modules\Bank\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    protected function casts(): array
    {
        return ['occurred_on' => 'date'];
    }
}
