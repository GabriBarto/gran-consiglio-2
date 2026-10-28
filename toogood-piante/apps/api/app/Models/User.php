<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

// role e status non sono assegnabili in massa: si impostano esplicitamente nel codice.
#[Fillable(['username', 'email', 'password'])]
#[Hidden(['password'])]
class User extends Authenticatable
{
    public const ROLE_CUSTOMER = 'CUSTOMER';

    public const ROLE_SELLER = 'SELLER';

    public const ROLE_ADMIN = 'ADMIN';

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_SUSPENDED = 'SUSPENDED';

    public const STATUS_DELETED = 'DELETED';

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'anonymized_at' => 'datetime',
            'must_change_password' => 'boolean',
            'password' => 'hashed',
        ];
    }
}
