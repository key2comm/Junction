<?php

namespace App\Models;

use App\Support\IpNet;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Settings -> Firewall whitelist ("allow") or blacklist ("block") entry:
 * an IP, a CIDR network or a hostname (resolved when the firewall syncs).
 */
#[Fillable(['list', 'value', 'reason', 'expires_at', 'resolved', 'resolved_at', 'user_id'])]
class FirewallEntry extends Model
{
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'resolved' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Entries in effect (blacklist entries can expire). */
    public function scopeActive(Builder $query): void
    {
        $query->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function isHostname(): bool
    {
        return IpNet::normalize($this->value) === null;
    }

    /** @return list<string> the IPs/networks this entry stands for */
    public function nets(): array
    {
        return $this->isHostname() ? array_values($this->resolved ?? []) : [$this->value];
    }
}
