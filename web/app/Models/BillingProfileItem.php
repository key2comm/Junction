<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['billing_item_id', 'quantity', 'price'])]
class BillingProfileItem extends Model
{
    protected function casts(): array
    {
        return ['quantity' => 'decimal:2', 'price' => 'decimal:6'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(BillingItem::class, 'billing_item_id');
    }
}
