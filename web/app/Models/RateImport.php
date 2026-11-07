<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One uploaded rate deck file and the outcome of applying it.
 */
#[Fillable(['rate_deck_id', 'user_id', 'filename', 'path', 'mode', 'effective_from', 'status', 'stats', 'errors', 'started_at', 'finished_at'])]
class RateImport extends Model
{
    protected function casts(): array
    {
        return [
            'effective_from' => 'datetime',
            'stats' => 'array',
            'errors' => 'array',
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
