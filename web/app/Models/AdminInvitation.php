<?php

namespace App\Models;

use App\Support\AdminRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * An invitation to become an admin. The link carries a random token; only its
 * hash is stored. Accepting it creates the user and deletes the invitation.
 */
class AdminInvitation extends Model
{
    public const DAYS = 7;

    protected $fillable = ['email', 'role', 'invited_by', 'token_hash', 'expires_at'];

    protected function casts(): array
    {
        return ['role' => AdminRole::class, 'expires_at' => 'datetime'];
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /** Give the invitation a new link (valid DAYS days); returns the token for the URL. */
    public function issueToken(): string
    {
        $token = Str::random(48);
        $this->forceFill(['token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(self::DAYS)])->save();

        return $token;
    }

    public static function findByToken(string $token): ?self
    {
        return self::query()->where('token_hash', hash('sha256', $token))->where('expires_at', '>', now())->first();
    }

    public function expired(): bool
    {
        return $this->expires_at->isPast();
    }
}
