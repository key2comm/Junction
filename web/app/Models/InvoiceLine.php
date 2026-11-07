<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['invoice_id', 'position', 'prefix', 'destination', 'calls', 'billsec', 'rate', 'amount'])]
class InvoiceLine extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'calls' => 'integer',
            'billsec' => 'integer',
            'rate' => 'decimal:6',
            'amount' => 'decimal:6',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
