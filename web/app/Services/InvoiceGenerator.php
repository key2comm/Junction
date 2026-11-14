<?php

namespace App\Services;

use App\Enums\BillingType;
use App\Jobs\EmailInvoice;
use App\Models\Account;
use App\Models\Invoice;
use App\Support\Billing;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Builds a customer statement for one billing period from the ledger and the
 * rated call records:
 *
 *   opening balance  = ledger total effective before the period
 *   + payments, adjustments, usage effective in the period
 *   = closing balance
 *
 * Usage is detailed by destination from the CDRs started in the period. The
 * ledger usage for the period must equal the CDR lines exactly (usage is posted
 * in hour batches dated by their calls); if not, nothing is issued.
 */
class InvoiceGenerator
{
    /** Calendar month containing $month, in the account's time zone, as UTC instants. */
    public static function monthPeriod(Account $account, CarbonImmutable $month): array
    {
        $start = $month->setTimezone($account->timezone)->startOfMonth();

        return [$start->utc(), $start->addMonth()->utc()];
    }

    public function generate(Account $account, CarbonImmutable $start, CarbonImmutable $end): Invoice
    {
        if (! Billing::enabled()) {
            throw new RuntimeException('Billing is turned off (Settings → Billing).');
        }
        if (! $account->is_customer) {
            throw new RuntimeException("{$account->code} is not a customer account.");
        }
        if ($end->isAfter(now())) {
            throw new RuntimeException('The period has not ended yet.');
        }

        // Every rated call in the period must be on the ledger first, and next
        // month's plan charges (billed in advance) go on this invoice.
        Artisan::call('softswitch:post-usage', ['--account' => $account->id]);
        app(BillingCharges::class)->runMonthly($account);

        return DB::transaction(function () use ($account, $start, $end) {
            $existing = Invoice::query()->where('account_id', $account->id)
                ->where('period_start', $start)->where('status', 'issued')->lockForUpdate()->first();
            if ($existing) {
                throw new RuntimeException("Invoice {$existing->number} already covers this period; void it first to re-issue.");
            }

            $ledger = fn () => DB::table('ledger_entries')->where('account_id', $account->id);
            $opening = (string) ($ledger()->where('effective_at', '<', $start)->sum('amount') ?: '0');
            $byType = $ledger()->where('effective_at', '>=', $start)->where('effective_at', '<', $end)
                ->groupBy('type')->selectRaw('type, sum(amount) AS total')->pluck('total', 'type');
            $sum = fn (string $type) => bcadd((string) ($byType[$type] ?? '0'), '0', 6);

            $usageLedger = bcmul($sum('usage'), '-1', 6);    // positive charge
            $payments = $sum('payment');
            $adjustments = $sum('adjustment');
            $chargesLedger = bcmul($sum('charge'), '-1', 6);  // positive charge; credits lower it
            $taxesLedger = bcmul($sum('tax'), '-1', 6);
            $closing = bcadd(bcadd(bcadd(bcadd(bcadd($opening, $payments, 6), $adjustments, 6), $sum('usage'), 6), $sum('charge'), 6), $sum('tax'), 6);

            // Billing → Taxes, totalled per tax and rate.
            $taxes = DB::table('tax_postings')->where('account_id', $account->id)
                ->where('effective_at', '>=', $start)->where('effective_at', '<', $end)->whereNotNull('ledger_entry_id')
                ->groupBy('name', 'rate')->selectRaw('name, rate, sum(base) AS base, sum(amount) AS amount, min(id) AS first')
                ->orderBy('first')->get();
            $taxLines = $taxes->reduce(fn (string $t, $x) => bcadd($t, (string) $x->amount, 6), '0');
            if (bccomp($taxLines, $taxesLedger, 6) !== 0) {
                throw new RuntimeException("Taxes on the ledger ({$taxesLedger}) do not match the tax postings ({$taxLines}) for this period; not issuing.");
            }

            // Plan items (Billing profiles), each as posted.
            $charges = DB::table('billing_charges')->where('account_id', $account->id)
                ->where('effective_at', '>=', $start)->where('effective_at', '<', $end)->whereNotNull('ledger_entry_id')
                ->orderBy('effective_at')->orderBy('id')->get(['description', 'quantity', 'unit_price', 'amount']);
            $chargeLines = $charges->reduce(fn (string $t, $c) => bcadd($t, (string) $c->amount, 6), '0');
            if (bccomp($chargeLines, $chargesLedger, 6) !== 0) {
                throw new RuntimeException("Plan charges on the ledger ({$chargesLedger}) do not match the billing charges ({$chargeLines}) for this period; not issuing.");
            }
            $includedSeconds = (int) DB::table('cdrs')->where('customer_account_id', $account->id)
                ->where('started_at', '>=', $start)->where('started_at', '<', $end)->sum('included_seconds');

            $lines = DB::table('cdrs')
                ->where('customer_account_id', $account->id)
                ->where('started_at', '>=', $start)->where('started_at', '<', $end)
                ->whereNotNull('sell_cost')->where('billsec', '>', 0)
                ->groupBy('sell_prefix')
                ->selectRaw('sell_prefix AS prefix, max(sell_destination) AS destination, count(*) AS calls,
                             sum(billsec) AS billsec, max(sell_rate) AS rate, sum(sell_cost) AS amount')
                ->orderByRaw('sum(sell_cost) DESC, sell_prefix')
                ->get();
            $usageLines = $lines->reduce(fn (string $t, $l) => bcadd($t, (string) $l->amount, 6), '0');

            if (bccomp($usageLines, $usageLedger, 6) !== 0) {
                throw new RuntimeException("Usage on the ledger ({$usageLedger}) does not match the call records ({$usageLines}) for this period; not issuing.");
            }

            $postpaid = $account->billing_type === BillingType::Postpaid;
            $issued = now();
            $invoice = Invoice::create([
                'number' => sprintf('INV-%s-%06d', $issued->format('Y'), DB::selectOne("SELECT nextval('invoice_number_seq') AS n")->n),
                'account_id' => $account->id,
                'status' => 'issued',
                'period_start' => $start,
                'period_end' => $end,
                'timezone' => $account->timezone,
                'currency' => $account->currency,
                'account_name' => $account->name,
                'account_code' => $account->code,
                'billing_email' => $account->billing_email,
                'billing_type' => $account->billing_type->value,
                'opening_balance' => $opening,
                'usage_total' => $usageLedger,
                'payments_total' => $payments,
                'adjustments_total' => $adjustments,
                'charges_total' => $chargesLedger,
                'taxes_total' => $taxesLedger,
                'tax_region' => $account->tax_region,
                'tax_numbers' => Billing::taxNumbers(),
                'included_seconds' => $includedSeconds,
                'closing_balance' => $closing,
                // Postpaid customers owe a negative closing balance; prepaid accounts pay in advance.
                'amount_due' => $postpaid && bccomp($closing, '0', 6) < 0 ? bcmul($closing, '-1', 6) : '0',
                'calls' => (int) $lines->sum('calls'),
                'billsec' => (int) $lines->sum('billsec'),
                'issued_at' => $issued,
                'due_at' => $postpaid ? $issued->addDays(Billing::dueDays()) : null,
            ]);

            foreach ($lines->values() as $i => $l) {
                $invoice->lines()->create([
                    'position' => $i + 1,
                    'prefix' => $l->prefix,
                    'destination' => $l->destination,
                    'calls' => (int) $l->calls,
                    'billsec' => (int) $l->billsec,
                    'rate' => $l->rate,
                    'amount' => $l->amount,
                ]);
            }

            foreach ($charges->values() as $i => $c) {
                $invoice->charges()->create([
                    'position' => $i + 1,
                    'description' => $c->description,
                    'quantity' => $c->quantity,
                    'unit_price' => $c->unit_price,
                    'amount' => $c->amount,
                ]);
            }

            foreach ($taxes->values() as $i => $x) {
                $invoice->taxes()->create(['position' => $i + 1, 'name' => $x->name, 'rate' => $x->rate, 'base' => $x->base, 'amount' => $x->amount]);
            }

            // Sent after the transaction commits (setting in Settings → Billing).
            if (Billing::autoEmailInvoices() && $invoice->billing_email) {
                EmailInvoice::dispatch($invoice)->afterCommit();
            }

            return $invoice;
        });
    }

    /**
     * Issue last month's invoice for every customer whose month (in their own
     * time zone) ended at least $graceHours ago and has none yet. Accounts with
     * no balance and no activity are skipped. Nothing is issued while billing is off.
     *
     * @return list<array{account: string, result: string}>
     */
    public function issueDue(int $graceHours = 2): array
    {
        $results = [];
        if (! Billing::enabled()) {
            return $results;
        }
        foreach (Account::query()->customers()->where('status', '!=', 'closed')->orderBy('id')->get() as $account) {
            [$start, $end] = self::monthPeriod($account, now()->subMonthNoOverflow());
            if ($end->addHours($graceHours)->isAfter(now())) {
                continue; // their month hasn't closed long enough ago
            }
            if (Invoice::query()->where('account_id', $account->id)->where('period_start', $start)->where('status', 'issued')->exists()) {
                continue;
            }
            if (! $this->hasActivity($account, $start, $end)) {
                continue;
            }

            try {
                $invoice = $this->generate($account, $start, $end);
                $results[] = ['account' => $account->code, 'result' => "issued {$invoice->number}"];
            } catch (RuntimeException $e) {
                $results[] = ['account' => $account->code, 'result' => 'failed: '.$e->getMessage()];
            }
        }

        return $results;
    }

    private function hasActivity(Account $account, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        return bccomp((string) $account->balance, '0', 6) !== 0
            || DB::table('ledger_entries')->where('account_id', $account->id)->where('effective_at', '>=', $start)->where('effective_at', '<', $end)->exists()
            || DB::table('cdrs')->where('customer_account_id', $account->id)->where('started_at', '>=', $start)->where('started_at', '<', $end)->exists();
    }
}
