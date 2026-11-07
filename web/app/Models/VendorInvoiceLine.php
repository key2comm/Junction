<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['vendor_invoice_id', 'prefix', 'destination', 'calls', 'minutes', 'amount'])]
class VendorInvoiceLine extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['calls' => 'integer', 'minutes' => 'decimal:2', 'amount' => 'decimal:6'];
    }
}
