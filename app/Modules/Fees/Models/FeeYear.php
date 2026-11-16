<?php

namespace App\Modules\Fees\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FeeYear extends Model
{
    protected $fillable = ['year', 'amount_cents'];

    public function dues(): HasMany
    {
        return $this->hasMany(Due::class);
    }
}
