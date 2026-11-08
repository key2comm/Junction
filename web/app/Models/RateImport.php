<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One uploaded rate deck file (or tariff build) and the outcome of applying it.
 * Status: mapping (waiting for the admin to match columns), queued, running, done, failed.
 */
#[Fillable(['rate_deck_id', 'user_id', 'filename', 'path', 'mode', 'effective_from', 'status', 'stats', 'errors', 'preview', 'mapping', 'started_at', 'finished_at'])]
class RateImport extends Model
{
    protected function casts(): array
    {
        return [
            'effective_from' => 'datetime',
            'stats' => 'array',
            'errors' => 'array',
            'preview' => 'array',
            'mapping' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function rateDeck(): BelongsTo
    {
        return $this->belongsTo(RateDeck::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isPending(): bool
    {
        return in_array($this->status, ['queued', 'running'], true);
    }
}
