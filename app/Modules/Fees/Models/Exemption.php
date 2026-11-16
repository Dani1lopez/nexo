<?php

namespace App\Modules\Fees\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Exemption extends Model
{
    public function due(): BelongsTo
    {
        return $this->belongsTo(Due::class);
    }

    protected function casts(): array
    {
        return ['revoked_at' => 'datetime'];
    }
}
