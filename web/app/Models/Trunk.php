<?php

namespace App\Models;

use App\Jobs\SyncSwitchConfig;
use Database\Factories\TrunkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A vendor's egress product: a buy rate deck plus one or more gateways.
 */
#[Fillable(['account_id', 'name', 'rate_deck_id', 'enabled', 'max_channels', 'max_cps', 'tech_prefix', 'strip_digits'])]
class Trunk extends Model
{
    /** @use HasFactory<TrunkFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'max_channels' => 'integer',
            'max_cps' => 'decimal:2',
            'strip_digits' => 'integer',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function rateDeck(): BelongsTo
    {
        return $this->belongsTo(RateDeck::class);
    }

    public function gateways(): HasMany
    {
        return $this->hasMany(Gateway::class);
    }

    public function routes(): HasMany
    {
        return $this->hasMany(RoutingPlanRoute::class);
    }

    /** The number as sent to this vendor: strip leading digits, then add the tech prefix. */
    public function formatNumber(string $number): string
    {
        return $this->tech_prefix.substr($number, $this->strip_digits);
    }

    protected static function booted(): void
    {
        // Gateways and the firewall allowlist follow the database.
        static::saved(fn () => SyncSwitchConfig::soon());
        static::deleted(fn () => SyncSwitchConfig::soon());
    }
}
