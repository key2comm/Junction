<?php

namespace App\Services;

use App\Enums\LedgerEntryType;
use App\Models\Account;
use App\Models\AccountCharge;
use App\Models\BillingCharge;
use App\Models\BillingItem;
use App\Models\BillingProfile;
use App\Models\Invoice;
use App\Models\User;
use App\Support\Billing;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Plan charges (Billing → Billing profiles / items) on customers' ledgers.
 *
 * Monthly items are charged in advance: each month's charge is posted when the
 * month starts in the customer's time zone (dated the last second of the month
 * before, so it's on the invoice issued then, beside the last month's calls).
 * Adding, changing or removing a monthly item mid-month posts the prorated
 * difference for the rest of the month (by days, today included). One-time
 * items are charged when added.
 *
 * Nothing is posted while billing is off (Settings → Billing).
 */
class BillingCharges
{
    public function __construct(private Ledger $ledger, private Taxes $taxes) {}

    /** Give the customer a profile (or none): its items replace the old profile's. */
    public function assignProfile(Account $account, ?BillingProfile $profile, ?User $user = null): void
    {
        DB::transaction(function () use ($account, $profile, $user) {
            foreach ($account->charges()->where('from_profile', true)->with('item')->get() as $line) {
                $this->remove($line, $user);
            }
            $account->forceFill(['billing_profile_id' => $profile?->id])->save();
            foreach ($profile?->items()->with('item')->get() ?? [] as $pi) {
                $this->add($account, $pi->item, (string) $pi->quantity, $pi->price === null ? null : (string) $pi->price, true, $user);
            }
        });
    }

    public function add(Account $account, BillingItem $item, string $quantity, ?string $price = null, bool $fromProfile = false, ?User $user = null): AccountCharge
    {
        return DB::transaction(function () use ($account, $item, $quantity, $price, $fromProfile, $user) {
            $line = $account->charges()->create([
                'billing_item_id' => $item->id, 'quantity' => $quantity, 'price' => $price, 'from_profile' => $fromProfile,
            ]);
            $line->setRelation('item', $item);
            if (! Billing::enabled()) {
                return $line;
            }
            $now = now();
            if ($item->isMonthly()) {
                [$days, $inMonth] = $this->remainingDays($account, $now);
                $this->post($line, 'prorated', $this->period($account, $now), bcdiv(bcmul($line->total(), (string) $days, 12), (string) $inMonth, 12),
                    $this->describe($line, $this->monthName($account, $now))." (prorated, {$days} of {$inMonth} days)", $now, $user);
            } else {
                $this->post($line, 'once', null, $line->total(), $this->describe($line), $now, $user);
            }

            return $line;
        });
    }

    /** New quantity or price; monthly items post the prorated difference for the rest of the month. */
    public function update(AccountCharge $line, string $quantity, ?string $price, ?User $user = null): void
    {
        DB::transaction(function () use ($line, $quantity, $price, $user) {
            $line->loadMissing('item', 'account');
            $before = $line->total();
            $line->update(['quantity' => $quantity, 'price' => $price]);
            $delta = bcsub($line->total(), $before, 6);
            if ($line->item->isMonthly() && bccomp($delta, '0', 6) !== 0 && $this->chargedThisMonth($line)) {
                $now = now();
                [$days, $inMonth] = $this->remainingDays($line->account, $now);
                $this->post($line, 'change', null, bcdiv(bcmul($delta, (string) $days, 12), (string) $inMonth, 12),
                    'Changed: '.$this->describe($line, $this->monthName($line->account, $now))." (prorated, {$days} of {$inMonth} days)", $now, $user);
            }
        });
    }

    /** Take an item off; a monthly one is credited for the rest of the month. */
    public function remove(AccountCharge $line, ?User $user = null): void
    {
        DB::transaction(function () use ($line, $user) {
            $line->loadMissing('item', 'account');
            if ($line->item->isMonthly() && $this->chargedThisMonth($line)) {
                $now = now();
                [$days, $inMonth] = $this->remainingDays($line->account, $now);
                $this->post($line, 'change', null, bcmul(bcdiv(bcmul($line->total(), (string) $days, 12), (string) $inMonth, 12), '-1', 12),
                    'Removed: '.$this->describe($line, $this->monthName($line->account, $now))." (credit, {$days} of {$inMonth} days)", $now, $user);
            }
            $line->delete();
        });
    }

    /**
     * Charge this month's monthly items to every customer (or one) not yet
     * charged for it. Safe to run any number of times.
     *
     * @return int charges posted
     */
    public function runMonthly(?Account $only = null): int
    {
        if (! Billing::enabled()) {
            return 0;
        }
        $posted = 0;
        $accounts = $only ? collect([$only]) : Account::query()->customers()->where('status', '!=', 'closed')
            ->whereHas('charges.item', fn ($q) => $q->where('frequency', 'monthly'))->orderBy('id')->get();
        foreach ($accounts as $account) {
            $now = now();
            $period = $this->period($account, $now);
            $monthStart = $now->setTimezone($account->timezone)->startOfMonth();
            // Dated just before the month starts, so the invoice for last month
            // carries it, unless that invoice has already gone out.
            $previousInvoiced = Invoice::query()->where('account_id', $account->id)->where('status', 'issued')
                ->where('period_end', '>', $monthStart->subSecond()->utc())->exists();
            $effective = $previousInvoiced ? $now : $monthStart->subSecond();

            foreach ($account->charges()->with('item')->get() as $line) {
                if (! $line->item->isMonthly() || BillingCharge::query()->where('account_charge_id', $line->id)->where('period', $period)->exists()) {
                    continue;
                }
                $this->post($line, 'monthly', $period, $line->total(), $this->describe($line, $this->monthName($account, $now)), $effective, null);
                $posted++;
            }
        }

        return $posted;
    }

    private function chargedThisMonth(AccountCharge $line): bool
    {
        return BillingCharge::query()->where('account_charge_id', $line->id)->where('period', $this->period($line->account, now()))->exists();
    }

    private function post(AccountCharge $line, string $kind, ?string $period, string $amount, string $description, \DateTimeInterface $at, ?User $user): void
    {
        $amount = Decimal::round($amount, 2);
        $charge = BillingCharge::create([
            'account_id' => $line->account_id,
            'account_charge_id' => $line->id,
            'kind' => $kind,
            'period' => $period,
            'description' => $description,
            'quantity' => $line->quantity,
            'unit_price' => $line->unitPrice(),
            'amount' => $amount,
            'effective_at' => $at,
        ]);
        if (bccomp($amount, '0', 2) === 0) {
            return; // a free item: on the bill, nothing on the ledger
        }
        $account = $line->relationLoaded('account') ? $line->account : Account::findOrFail($line->account_id);
        $entry = $this->ledger->post($account, LedgerEntryType::Charge, bcmul($amount, '-1', 6), $description, $charge, $user, $at);
        $charge->update(['ledger_entry_id' => $entry->id]);
        // Billing → Taxes: tax on the charge (or its credit), dated with it.
        $this->taxes->post($account, $amount, 'charge', $line->item->name, $at);
    }

    /** "Unlimited hosting × 12 per ext — December 2026" */
    private function describe(AccountCharge $line, ?string $month = null): string
    {
        $qty = rtrim(rtrim((string) $line->quantity, '0'), '.');
        $unit = $line->item->unit && $line->item->unit !== 'each' ? " {$line->item->unit}" : '';

        return $line->item->name." × {$qty}{$unit}".($month ? " — {$month}" : '');
    }

    public function period(Account $account, \DateTimeInterface $at): string
    {
        return CarbonImmutable::instance($at)->setTimezone($account->timezone)->format('Y-m');
    }

    private function monthName(Account $account, \DateTimeInterface $at): string
    {
        return CarbonImmutable::instance($at)->setTimezone($account->timezone)->format('F Y');
    }

    /** @return array{0: int, 1: int} days left in the month (today included), days in the month */
    private function remainingDays(Account $account, \DateTimeInterface $at): array
    {
        $local = CarbonImmutable::instance($at)->setTimezone($account->timezone);

        return [$local->daysInMonth - $local->day + 1, $local->daysInMonth];
    }
}
