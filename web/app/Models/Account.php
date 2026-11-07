<?php

namespace App\Models;

use App\Enums\AccountStatus;
use App\Enums\BillingType;
use App\Jobs\SyncSwitchConfig;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * A company we exchange traffic with: a customer (sends us calls), a vendor
 * (we send calls to them), or both. `balance` is only ever changed through
 * App\Services\Ledger.
 */
#[Fillable([
    'name', 'code', 'is_customer', 'is_vendor', 'status', 'currency', 'billing_type',
    'credit_limit', 'low_balance_threshold', 'timezone', 'billing_email', 'noc_email', 'sell_rate_deck_id',
    'routing_plan_id', 'max_channels', 'max_cps', 'notes',
])]
class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory;

    protected $attributes = [
        'balance' => 0,
        'vendor_balance' => 0,
    ];

    protected function casts(): array
    {
        return [
            'is_customer' => 'boolean',
            'is_vendor' => 'boolean',
            'status' => AccountStatus::class,
            'billing_type' => BillingType::class,
            'credit_limit' => 'decimal:4',
            'low_balance_threshold' => 'decimal:4',
            'low_balance_alerted_at' => 'datetime',
            'balance' => 'decimal:6',
            'vendor_balance' => 'decimal:6',
            'max_channels' => 'integer',
            'max_cps' => 'decimal:2',
        ];
    }

    public function ips(): HasMany
    {
        return $this->hasMany(AccountIp::class);
    }

    public function pbxExtensions(): HasMany
    {
        return $this->hasMany(PbxExtension::class);
    }

    public function pbxIvrs(): HasMany
    {
        return $this->hasMany(PbxIvr::class);
    }

    public function pbxDids(): HasMany
    {
        return $this->hasMany(PbxDid::class);
    }

    public function trunks(): HasMany
    {
        return $this->hasMany(Trunk::class);
    }

    public function sellRateDeck(): BelongsTo
    {
        return $this->belongsTo(RateDeck::class, 'sell_rate_deck_id');
    }

    public function routingPlan(): BelongsTo
    {
        return $this->belongsTo(RoutingPlan::class);
    }

    public function blockedPrefixes(): HasMany
    {
        return $this->hasMany(BlockedPrefix::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    /** What we owe this account as a vendor (payables). */
    public function vendorLedgerEntries(): HasMany
    {
        return $this->hasMany(VendorLedgerEntry::class);
    }

    public function vendorInvoices(): HasMany
    {
        return $this->hasMany(VendorInvoice::class);
    }

    /**
     * Funds available for new calls: balance plus credit (postpaid credit or
     * prepaid overdraft allowance).
     */
    public function availableFunds(): string
    {
        return bcadd((string) $this->balance, (string) $this->credit_limit, 6);
    }

    /**
     * Funds the routing engine would see right now: balance + credit, less
     * calls in progress (reservations) and rated usage not yet posted. Mirrors
     * softswitch_route() in PostgreSQL.
     */
    public function availableFundsNow(): string
    {
        $reserved = (string) DB::table('active_calls')->where('account_id', $this->id)->sum('reserved');
        $unposted = (string) DB::table('cdrs')->where('customer_account_id', $this->id)->whereNull('posted_at')->whereNotNull('sell_cost')->sum('sell_cost');

        return bcsub(bcsub($this->availableFunds(), $reserved, 6), $unposted, 6);
    }

    public function scopeCustomers(Builder $query): void
    {
        $query->where('is_customer', true);
    }

    public function scopeVendors(Builder $query): void
    {
        $query->where('is_vendor', true);
    }

    protected static function booted(): void
    {
        // A customer's IPs leave the firewall when the account closes or stops being a customer.
        static::updated(function (Account $account) {
            if ($account->wasChanged(['status', 'is_customer'])) {
                SyncSwitchConfig::soon();
            }
        });
    }
}
