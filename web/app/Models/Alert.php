<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A fraud or operational problem found by App\Services\AlertEngine. */
#[Fillable(['type', 'key', 'severity', 'status', 'account_id', 'trunk_id', 'gateway_id', 'title', 'details', 'action_taken',
    'opened_at', 'last_seen_at', 'resolved_at', 'acknowledged_by', 'acknowledged_at', 'emailed_at'])]
class Alert extends Model
{
    protected function casts(): array
    {
        return [
            'details' => 'array',
            'opened_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'emailed_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function trunk(): BelongsTo
    {
        return $this->belongsTo(Trunk::class);
    }

    public function gateway(): BelongsTo
    {
        return $this->belongsTo(Gateway::class);
    }
}
