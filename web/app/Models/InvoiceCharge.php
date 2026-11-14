<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** A plan item on an invoice (Billing profiles), as charged. */
#[Fillable(['invoice_id', 'position', 'description', 'quantity', 'unit_price', 'amount'])]
class InvoiceCharge extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['quantity' => 'decimal:2', 'unit_price' => 'decimal:6', 'amount' => 'decimal:6'];
    }
}
