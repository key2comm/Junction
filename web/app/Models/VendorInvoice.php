<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An invoice received from a vendor, with our own CDR figures for the same
 * period alongside for reconciliation.
 */
#[Fillable(['account_id', 'reference', 'period_start', 'period_end', 'timezone', 'currency', 'amount', 'calls', 'minutes',
    'our_amount', 'our_calls', 'our_minutes', 'status', 'notes', 'filename', 'user_id', 'resolved_at'])]
class VendorInvoice extends Model
{
    protected function casts(): array
    {
        return [
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'amount' => 'decimal:6',
            'calls' => 'integer',
            'minutes' => 'decimal:2',
            'our_amount' => 'decimal:6',
            'our_calls' => 'integer',
            'our_minutes' => 'decimal:2',
            'resolved_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(VendorInvoiceLine::class)->orderBy('prefix');
    }

    /** Their amount minus ours: positive means they bill more than our records show. */
    public function difference(): string
    {
        return bcsub((string) $this->amount, (string) $this->our_amount, 6);
    }
}
