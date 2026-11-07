<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Alert;
use App\Models\Invoice;
use App\Models\VendorInvoice;
use App\Services\MailSettings;
use App\Support\Billing;
use App\Support\CdrFilters;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        $now = CarbonImmutable::now('UTC');
        $last24h = CdrController::summary(new CdrFilters($now->subDay(), $now, []));

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
}
