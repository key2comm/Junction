<?php

namespace App\Models;

use App\Jobs\SyncSwitchConfig;
use Database\Factories\AccountIpFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A source IP or CIDR (plus optional tech prefix) that identifies a customer's
 * traffic on the carrier profile. Also feeds the firewall allowlist.
 */
#[Fillable(['account_id', 'ip', 'tech_prefix', 'description', 'enabled'])]
class AccountIp extends Model
{
    /** @use HasFactory<AccountIpFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    protected static function booted(): void
    {
        // Gateways and the firewall allowlist follow the database.
        static::saved(fn () => SyncSwitchConfig::soon());
        static::deleted(fn () => SyncSwitchConfig::soon());
    }
}
