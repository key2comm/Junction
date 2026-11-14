<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Billing → Billing profiles: the items a customer is billed for, and how
 * their Canada/USA calls are charged:
 *   metered    every call at the tariff rate
 *   unlimited  Canada/USA calls are included (international still pays)
 *   bundle     nanp_minutes a month included, then the tariff rate
 */
#[Fillable(['name', 'description', 'nanp_plan', 'nanp_minutes'])]
class BillingProfile extends Model
{
    public const PLANS = [
        'metered' => 'Pay per minute',
        'unlimited' => 'Unlimited Canada/USA',
        'bundle' => 'Included Canada/USA minutes',
    ];

    protected function casts(): array
    {
        return ['nanp_minutes' => 'integer'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(BillingProfileItem::class)->orderBy('id');
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    /** "Unlimited Canada/USA", "5,000 Canada/USA minutes", "Pay per minute". */
    public function planLabel(): string
    {
        return $this->nanp_plan === 'bundle'
            ? number_format((int) $this->nanp_minutes).' Canada/USA minutes a month'
            : self::PLANS[$this->nanp_plan] ?? $this->nanp_plan;
    }
}
