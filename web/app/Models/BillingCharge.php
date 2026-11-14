<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** A plan charge posted to a customer's ledger (see BillingCharges). */
#[Fillable(['account_id', 'account_charge_id', 'kind', 'period', 'description', 'quantity', 'unit_price', 'amount', 'ledger_entry_id', 'effective_at'])]
class BillingCharge extends Model
{
    protected function casts(): array
    {
        return ['quantity' => 'decimal:2', 'unit_price' => 'decimal:6', 'amount' => 'decimal:6', 'effective_at' => 'datetime'];
    }
}
