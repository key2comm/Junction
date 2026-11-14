<?php

namespace App\Services;

use App\Enums\LedgerEntryType;
use App\Models\Account;
use App\Models\Tax;
use App\Support\Billing;
use App\Support\Decimal;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Billing → Taxes: each charge to a customer (plan items, call usage) is
 * taxed when it's posted, at the active rates of the customer's tax region,
 * so a prepaid balance always includes tax. Credits get their tax back.
 * Customers without a region aren't taxed.
 */
class Taxes
{
    public function __construct(private Ledger $ledger) {}

    /**
     * Tax $base (a charge, positive; a credit, negative) for $account.
     *
     * @param  'usage'|'charge'  $source
     */
    public function post(Account $account, string $base, string $source, string $what, DateTimeInterface $at): void
    {
        if (! Billing::enabled() || ! $account->tax_region || bccomp($base, '0', 6) === 0) {
            return;
        }
        foreach (Tax::query()->where('region', $account->tax_region)->where('active', true)->where('rate', '>', 0)->orderBy('position')->orderBy('id')->get() as $tax) {
            $amount = Decimal::round(bcdiv(bcmul($base, (string) $tax->rate, 12), '100', 12), 6);
            if (bccomp($amount, '0', 6) === 0) {
                continue;
            }
            DB::transaction(function () use ($account, $tax, $base, $amount, $source, $what, $at) {
                $rate = rtrim(rtrim((string) $tax->rate, '0'), '.');
                $entry = $this->ledger->post($account, LedgerEntryType::Tax, bcmul($amount, '-1', 6), "{$tax->name} {$rate}% on {$what}", null, null, $at);
                DB::table('tax_postings')->insert([
                    'account_id' => $account->id, 'tax_id' => $tax->id, 'name' => $tax->name, 'rate' => $tax->rate, 'source' => $source,
                    'base' => $base, 'amount' => $amount, 'ledger_entry_id' => $entry->id, 'effective_at' => $at, 'created_at' => now(), 'updated_at' => now(),
                ]);
            });
        }
    }

    /** "13% HST" style summary of a region's active taxes, e.g. for the customer page. */
    public static function summary(?string $region): ?string
    {
        if (! $region) {
            return null;
        }
        $parts = Tax::query()->where('region', $region)->where('active', true)->where('rate', '>', 0)->orderBy('position')->orderBy('id')->get()
            ->map(fn (Tax $t) => $t->name.' '.rtrim(rtrim((string) $t->rate, '0'), '.').'%');

        return $parts->isEmpty() ? 'no tax set' : $parts->implode(' + ');
    }
}
