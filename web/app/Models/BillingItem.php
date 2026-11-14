<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Billing → Billing items: one thing that can go on any customer's bill. */
#[Fillable(['name', 'description', 'unit', 'price', 'frequency', 'active'])]
class BillingItem extends Model
{
    public const FREQUENCIES = ['monthly' => 'Monthly', 'once' => 'One-time'];

    protected function casts(): array
    {
        return ['price' => 'decimal:6', 'active' => 'boolean'];
    }

    public function isMonthly(): bool
    {
        return $this->frequency === 'monthly';
    }
}
