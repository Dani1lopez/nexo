<?php

namespace App\Modules\Access\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use InvalidArgumentException;

class User extends Authenticatable
{
    /**
     * The only roles the application knows. Anything else fails closed.
     */
    public const ROLES = ['treasury', 'board'];

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token', 'two_factor_secret'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * Only a confirmed secret gates the login; an enrollment in progress does not.
     */
    public function hasConfirmedTwoFactor(): bool
    {
        return $this->two_factor_confirmed_at !== null;
    }

    /**
     * Set the role through the only supported write path.
     */
    public function assignRole(string $role): static
    {
        if (! in_array($role, self::ROLES, true)) {
            throw new InvalidArgumentException('Rol no permitido.');
        }

        $this->role = $role;

        return $this;
    }

    /**
     * Create an account with an explicit role; role is never mass assigned.
     */
    public static function provision(string $name, string $email, string $password, string $role): self
    {
        $user = new self(['name' => $name, 'email' => $email, 'password' => $password]);
        $user->assignRole($role);
        $user->save();

        return $user;
    }

    protected static function booted(): void
    {
        static::saving(function (User $user): void {
            // Only a write that changes the role is rejected; a row that already holds
            // an unknown value must still be able to save unrelated attributes.
            if (! $user->isDirty('role')) {
                return;
            }
            if ($user->role !== null && ! in_array($user->role, self::ROLES, true)) {
                throw new InvalidArgumentException('Rol no permitido.');
            }
        });
    }
}
