<?php

namespace App\Models;

use App\Support\AdminAreas;
use App\Support\AdminRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property AdminRole|null $role
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'account_id', 'role', 'timezone'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /** The zone the site shows times in (Settings → Profile); stored times stay UTC. */
    protected $attributes = ['timezone' => 'UTC'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'role' => AdminRole::class,
        ];
    }

    /** The customer account a portal user belongs to (null for admins). */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function isAdmin(): bool
    {
        return $this->account_id === null;
    }

    /** The admin's access level (Settings → Admin users); an admin without one only gets to look. */
    public function adminRole(): AdminRole
    {
        return $this->role ?? AdminRole::ReadOnly;
    }

    /** "write", "read" or null for one area of the admin UI (AdminAreas); portal users get nothing. */
    public function adminAccess(string $area): ?string
    {
        if (! $this->isAdmin()) {
            return null;
        }

        return $area === AdminAreas::ANY ? AdminRole::WRITE : $this->adminRole()->access($area);
    }
}
