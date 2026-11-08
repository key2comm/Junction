<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One IP of an endpoint's hosts (hostnames resolved by App\Services\EndpointSync). */
#[Fillable(['endpoint_id', 'ip'])]
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
