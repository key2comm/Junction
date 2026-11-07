<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['cdr_id', 'attempt', 'trunk_id', 'gateway_id', 'buy_prefix', 'buy_rate', 'started_at', 'answered_at', 'ended_at', 'sip_code', 'hangup_cause', 'pdd_ms'])]
class CdrAttempt extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'attempt' => 'integer',
            'buy_rate' => 'decimal:6',
            'started_at' => 'datetime',
            'answered_at' => 'datetime',
            'ended_at' => 'datetime',
            'sip_code' => 'integer',
            'pdd_ms' => 'integer',
        ];
    }

    public function cdr(): BelongsTo
    {
        return $this->belongsTo(Cdr::class);
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
