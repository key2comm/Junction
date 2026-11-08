<?php

namespace App\Models;

use App\Jobs\SyncSwitchConfig;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A device's "receiving calls" rule: how its outgoing calls are recognised.
 * type: domain (From domain), ip (IP address or hostname), number (caller
 * number exactly as sent). Unique switch-wide.
 */
#[Fillable(['endpoint_id', 'type', 'value'])]
class EndpointRule extends Model
{
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(Endpoint::class);
    }

    protected static function booted(): void
    {
        static::saved(fn () => SyncSwitchConfig::soon());
        static::deleted(fn () => SyncSwitchConfig::soon());
    }
}
