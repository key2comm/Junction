<?php

namespace App\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * A REST API bearer token ("ss_" + 48 random characters). Only its SHA-256
 * hash is stored.
 */
#[Hidden(['token_hash'])]
#[Fillable(['name', 'token_hash', 'token_prefix', 'abilities', 'user_id', 'account_id', 'last_used_at', 'last_used_ip', 'expires_at', 'revoked_at'])]
class ApiToken extends Model
{
    /** Everything an admin token can be granted. */
    public const ABILITIES = [
        'accounts:read' => 'Read accounts, IPs and balances',
        'accounts:write' => 'Create and change accounts and IPs, record payments',
        'rates:read' => 'Read rate decks and rates',
        'rates:write' => 'Create rate decks and import rates',
        'routing:read' => 'Read trunks, gateways and routing plans; test routes',
        'routing:write' => 'Change trunks, gateways and routing plans',
        'cdrs:read' => 'Read call records',
        'billing:read' => 'Read invoices',
        'alerts:read' => 'Read fraud and operational alerts',
    ];

    /** A token scoped to one customer account can only have these. */
    public const CUSTOMER_ABILITIES = ['accounts:read', 'cdrs:read', 'billing:read'];

    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Create a token. Returns [model, plaintext]; the plaintext can't be recovered later.
     *
     * @param  list<string>  $abilities
     * @return array{0: self, 1: string}
     */
    public static function issue(string $name, array $abilities, ?User $user = null, ?Account $account = null, ?DateTimeInterface $expiresAt = null): array
    {
        $allowed = $account ? self::CUSTOMER_ABILITIES : array_keys(self::ABILITIES);
        $unknown = array_diff($abilities, $allowed);
        if ($unknown !== [] || $abilities === []) {
            throw new InvalidArgumentException($abilities === [] ? 'Choose at least one ability.' : 'Not allowed: '.implode(', ', $unknown));
        }

        $plain = 'ss_'.Str::random(48);
        $token = self::create([
            'name' => $name,
            'token_hash' => hash('sha256', $plain),
            'token_prefix' => substr($plain, 0, 10),
            'abilities' => array_values(array_unique($abilities)),
            'user_id' => $user?->id,
            'account_id' => $account?->id,
            'expires_at' => $expiresAt,
        ]);

        return [$token, $plain];
    }

    public static function findByPlaintext(string $plain): ?self
    {
        if (! str_starts_with($plain, 'ss_') || strlen($plain) !== 51) {
            return null;
        }

        return self::query()->where('token_hash', hash('sha256', $plain))->first();
    }

    public function usable(): bool
    {
        return $this->revoked_at === null && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function can(string $ability): bool
    {
        return in_array($ability, $this->abilities ?? [], true);
    }
}
