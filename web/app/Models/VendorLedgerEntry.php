<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * An append-only payables entry: what we owe a vendor. Create through
 * App\Services\VendorLedger; the database rejects updates and deletes.
 */
#[Fillable(['account_id', 'type', 'amount', 'balance_after', 'currency', 'description', 'reference_type', 'reference_id', 'user_id', 'effective_at'])]
class VendorLedgerEntry extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:6',
            'balance_after' => 'decimal:6',
            'effective_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
