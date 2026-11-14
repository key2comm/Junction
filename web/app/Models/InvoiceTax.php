<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** One tax's total on an invoice (Billing → Taxes). */
#[Fillable(['invoice_id', 'position', 'name', 'rate', 'base', 'amount'])]
class InvoiceTax extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['rate' => 'decimal:4', 'base' => 'decimal:6', 'amount' => 'decimal:6'];
    }
}
