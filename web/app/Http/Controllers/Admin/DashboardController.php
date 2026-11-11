<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Alert;
use App\Models\Invoice;
use App\Models\VendorInvoice;
use App\Services\MailSettings;
use App\Services\SystemStats;
use App\Support\Billing;
use App\Support\CdrFilters;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $now = CarbonImmutable::now('UTC');
        $last24h = CdrController::summary(new CdrFilters($now->subDay(), $now, []));
        // "Today" and "this month" are the viewer's own (Settings → Profile → Time zone).
        $tz = $request->user()->timezone ?: 'UTC';
        $local = $now->setTimezone($tz);

        $billing = Billing::enabled(); // the billing lists are hidden while it's off
        $low = ! $billing ? [] : Account::query()->customers()->where('status', 'active')->whereNotNull('low_balance_alerted_at')
            ->orderBy('low_balance_alerted_at')->limit(20)->get()
            ->map(fn (Account $a) => [
                ...$a->only(['id', 'code', 'name', 'currency', 'low_balance_threshold', 'low_balance_alerted_at']),
                'available' => $a->availableFundsNow(),
            ]);

        return Inertia::render('Dashboard', [
            'last24h' => $last24h,
            'activeCalls' => DB::table('active_calls')->count(),
            'today' => self::counts($local->startOfDay(), $now),
            'month' => self::counts($local->startOfMonth(), $now),
            'week' => self::days($local->startOfDay()->subDays(6), 7, $tz),
            'hours' => self::hours($local->startOfHour()->subHours(23), 24, $tz),
            'system' => SystemStats::current(),
            'lowBalance' => $low,
            'unpaid' => ! $billing ? [] : Invoice::query()->where('status', 'issued')->where('amount_due', '>', 0)->orderBy('due_at')->limit(10)
                ->get(['id', 'number', 'account_code', 'account_name', 'currency', 'amount_due', 'due_at']),
            'disputes' => ! $billing ? [] : VendorInvoice::query()->where('status', '!=', 'accepted')->with('account:id,code')->orderBy('period_start')->limit(10)
                ->get(['id', 'account_id', 'reference', 'status', 'amount', 'our_amount', 'currency'])
                ->map(fn ($i) => [...$i->only(['id', 'reference', 'status', 'currency']), 'vendor' => $i->account->code, 'difference' => $i->difference()]),
            'alerts' => Alert::query()->whereIn('status', ['open', 'acknowledged'])->orderByRaw("CASE status WHEN 'open' THEN 0 ELSE 1 END")
                ->orderByDesc('opened_at')->limit(8)->get(['id', 'type', 'severity', 'status', 'title', 'action_taken', 'opened_at']),
            'mailConfigured' => MailSettings::configured(),
            'currency' => Account::query()->groupBy('currency')->orderByRaw('count(*) desc')->value('currency') ?? 'USD',
        ]);
    }

    /** @return array{calls: int, answered: int, minutes: float} */
    private static function counts(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $row = DB::table('cdrs')->where('started_at', '>=', $from->utc())->where('started_at', '<', $to)
            ->selectRaw('count(*) AS calls, count(answered_at) AS answered, coalesce(sum(billsec), 0) AS billsec')->first();

        return ['calls' => (int) $row->calls, 'answered' => (int) $row->answered, 'minutes' => round((int) $row->billsec / 60, 1)];
    }

    /** Calls per local hour ("YYYY-MM-DD HH"), oldest first, including hours with none. */
    private static function hours(CarbonImmutable $from, int $count, string $tz): array
    {
        $rows = DB::table('cdrs')->where('started_at', '>=', $from->utc())
            ->selectRaw("to_char(started_at AT TIME ZONE ?, 'YYYY-MM-DD HH24') AS hour, count(*) AS calls, count(answered_at) AS answered", [$tz])
            ->groupBy('hour')->get()->keyBy('hour');

        return collect(range(0, $count - 1))->map(function (int $i) use ($from, $rows) {
            $hour = $from->addHours($i)->format('Y-m-d H');
            $row = $rows->get($hour);

            return ['hour' => $hour, 'calls' => (int) ($row->calls ?? 0), 'answered' => (int) ($row->answered ?? 0)];
        })->all();
    }

    /** Calls per local day, oldest first, including days with none. */
    private static function days(CarbonImmutable $from, int $count, string $tz): array
    {
        $rows = DB::table('cdrs')->where('started_at', '>=', $from->utc())
            ->selectRaw("to_char(started_at AT TIME ZONE ?, 'YYYY-MM-DD') AS day, count(*) AS calls, count(answered_at) AS answered", [$tz])
            ->groupBy('day')->get()->keyBy('day');

        return collect(range(0, $count - 1))->map(function (int $i) use ($from, $rows) {
            $day = $from->addDays($i)->format('Y-m-d');
            $row = $rows->get($day);

            return ['day' => $day, 'calls' => (int) ($row->calls ?? 0), 'answered' => (int) ($row->answered ?? 0)];
        })->all();
    }
}
