<?php

namespace App\Models;

use App\Enums\LedgerEntryType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * An append-only money movement. Create entries through App\Services\Ledger,
 * which keeps accounts.balance in step; the database rejects updates/deletes.
 */
#[Fillable(['account_id', 'type', 'amount', 'balance_after', 'currency', 'description', 'reference_type', 'reference_id', 'user_id'])]
class LedgerEntry extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'type' => LedgerEntryType::class,
            'amount' => 'decimal:6',
            'balance_after' => 'decimal:6',
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
