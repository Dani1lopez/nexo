<?php

namespace App\Modules\Members\Models;

use Illuminate\Database\Eloquent\Model;

class Member extends Model
{
    public const ACTIVE = 'active';

    public const INACTIVE = 'inactive';

    /** Status is deliberately not fillable: it changes only through deactivate and reactivate. */
    protected $fillable = ['name', 'email', 'joined_on'];

    protected $attributes = ['status' => self::ACTIVE];

    protected function casts(): array
    {
        return ['joined_on' => 'date'];
    }
}
