<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A known IP of an endpoint (hostnames resolved by App\Services\EndpointSync):
 * purpose pbx_host (a PBX's host, a trusted source) or ip_rule (a device's
 * IP/FQDN receiving rule).
 */
#[Fillable(['endpoint_id', 'purpose', 'ip'])]
class EndpointAddress extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = null;

    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(Endpoint::class);
    }
}
