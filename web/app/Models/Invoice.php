<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customer statement for one billing period, generated from the ledger and
 * call records by App\Services\InvoiceGenerator. It never moves money.
 */
#[Fillable([
    'number', 'account_id', 'status', 'period_start', 'period_end', 'timezone', 'currency', 'account_name', 'account_code',
    'billing_email', 'billing_type', 'opening_balance', 'usage_total', 'payments_total', 'adjustments_total',
    'closing_balance', 'amount_due', 'calls', 'billsec', 'issued_at', 'due_at', 'void_reason', 'voided_at', 'emailed_at', 'emailed_to',
])]
class Invoice extends Model
{
    protected function casts(): array
    {
        return [
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'issued_at' => 'datetime',
            'due_at' => 'datetime',
            'voided_at' => 'datetime',
            'emailed_at' => 'datetime',
            'opening_balance' => 'decimal:6',
            'usage_total' => 'decimal:6',
            'payments_total' => 'decimal:6',
            'adjustments_total' => 'decimal:6',
            'closing_balance' => 'decimal:6',
            'amount_due' => 'decimal:6',
            'calls' => 'integer',
            'billsec' => 'integer',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('position');
    }
}
