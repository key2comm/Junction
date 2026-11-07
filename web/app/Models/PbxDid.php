<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A number carriers deliver to us, routed to a PBX destination of its account.
 * Read live by the call path (softswitch_pbx_did), so no switch sync is needed.
 */
#[Fillable(['account_id', 'number', 'destination', 'description', 'enabled'])]
class PbxDid extends Model
{
    protected function casts(): array
    {
        return [
            'destination' => 'array',
            'enabled' => 'boolean',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
