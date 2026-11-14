<?php

namespace App\Services;

use App\Models\Account;
use App\Models\BillingProfile;
use App\Models\Cdr;
use App\Models\Rate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Canada/USA calls a customer's billing profile covers (Billing → Billing
 * profiles): with "unlimited" every Canada/USA call is free; with included
 * minutes, calls are free until the month's minutes (customer's time zone) run
 * out, and the rest of a call that crosses the line pays the tariff rate.
 * International calls always pay. Runs inside the CDR's transaction.
 */
class IncludedMinutes
{
    /**
     * @return array{0: int, 1: string|null} [included seconds, sell cost]
     */
    public function rate(Cdr $cdr, ?Rate $sellRate, int $billsec): array
    {
        $this->release($cdr);
        $full = $sellRate?->costFor($billsec);
        if (! $sellRate || $billsec <= 0 || ! $cdr->customer_account_id || ! $cdr->destination) {
            return [0, $full];
        }
        $account = Account::query()->find($cdr->customer_account_id);
        $profile = $account?->billing_profile_id ? BillingProfile::query()->find($account->billing_profile_id) : null;
        if (! $profile || $profile->nanp_plan === 'metered' || ! self::isNanp($cdr->destination)) {
            return [0, $full];
        }
        if ($profile->nanp_plan === 'unlimited') {
            return [$billsec, '0.000000'];
        }

        $period = CarbonImmutable::parse($cdr->started_at)->setTimezone($account->timezone)->format('Y-m');
        DB::statement('INSERT INTO account_bundle_usage (account_id, period, seconds) VALUES (?, ?, 0) ON CONFLICT DO NOTHING', [$account->id, $period]);
        $used = (int) DB::table('account_bundle_usage')->where('account_id', $account->id)->where('period', $period)->lockForUpdate()->value('seconds');
        $left = max(0, (int) $profile->nanp_minutes * 60 - $used);
        $included = min($billsec, $left);
        if ($included > 0) {
            DB::table('account_bundle_usage')->where('account_id', $account->id)->where('period', $period)->increment('seconds', $included);
        }

        return [$included, $included >= $billsec ? '0.000000' : $sellRate->costFor($billsec - $included)];
    }

    /** A re-sent CDR gives back the minutes it took the first time. */
    private function release(Cdr $cdr): void
    {
        $before = (int) ($cdr->getOriginal('included_seconds') ?? 0);
        if (! $cdr->exists || $before <= 0 || ! $cdr->getOriginal('customer_account_id')) {
            return;
        }
        $account = Account::query()->find($cdr->getOriginal('customer_account_id'));
        if (! $account) {
            return;
        }
        $period = CarbonImmutable::parse($cdr->getOriginal('started_at'))->setTimezone($account->timezone)->format('Y-m');
        DB::table('account_bundle_usage')->where('account_id', $account->id)->where('period', $period)
            ->update(['seconds' => DB::raw('greatest(0, seconds - '.$before.')')]);
    }

    public static function isNanp(string $number): bool
    {
        return DB::selectOne('SELECT softswitch_dial_country(?) AS c', [preg_replace('/\D/', '', $number)])->c === 'NANP';
    }

    /** Included seconds used this month (for the customer page). */
    public static function usedThisMonth(Account $account): int
    {
        return (int) DB::table('account_bundle_usage')->where('account_id', $account->id)
            ->where('period', now()->setTimezone($account->timezone)->format('Y-m'))->value('seconds');
    }
}
