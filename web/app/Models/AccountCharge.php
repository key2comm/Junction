<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line on a customer's bill: an item, how many, and the price (null
 * follows the item's catalog price). Changed only through BillingCharges,
 * which posts the prorated difference.
 */
#[Fillable(['account_id', 'billing_item_id', 'quantity', 'price', 'from_profile'])]
class AccountCharge extends Model
{
    protected function casts(): array
    {
        return ['quantity' => 'decimal:2', 'price' => 'decimal:6', 'from_profile' => 'boolean'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(BillingItem::class, 'billing_item_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function unitPrice(): string
    {
        return (string) ($this->price ?? $this->item->price);
    }

    /** quantity × unit price, for one month (or once). */
    public function total(): string
    {
        return bcmul((string) $this->quantity, $this->unitPrice(), 6);
    }
}
