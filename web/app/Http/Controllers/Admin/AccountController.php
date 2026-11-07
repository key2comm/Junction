<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AccountStatus;
use App\Enums\BillingType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AccountRequest;
use App\Models\Account;
use App\Models\Invoice;
use App\Models\RateDeck;
use App\Models\RoutingPlan;
use App\Models\Trunk;
use App\Models\User;
use App\Services\Router;
use App\Support\Currencies;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Accounts. Billing → Accounts lists everyone and creates customers;
 * Routing → Carriers (/carriers, scope "carrier") lists and creates carriers.
 */
class AccountController extends Controller
{
    private static function carrierScope(Request $request): bool
    {
        return $request->route('scope') === 'carrier';
    }

    public function index(Request $request): Response
    {
        $carriers = self::carrierScope($request);
        if ($carriers) {
            $request->merge(['role' => 'vendor']);
        }
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', 'in:customer,vendor'],
            'status' => ['nullable', 'in:'.implode(',', AccountStatus::values())],
        ]);

        $accounts = Account::query()
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($q) => $q
                ->whereLike('name', "%{$term}%", caseSensitive: false)
                ->orWhereLike('code', "%{$term}%", caseSensitive: false)))
            ->when(($filters['role'] ?? null) === 'customer', fn ($q) => $q->customers())
            ->when(($filters['role'] ?? null) === 'vendor', fn ($q) => $q->vendors())
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->withCount('trunks')
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Account $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'code' => $a->code,
                'is_customer' => $a->is_customer,
                'is_vendor' => $a->is_vendor,
                'status' => $a->status->value,
                'billing_type' => $a->billing_type->value,
                'currency' => $a->currency,
                'balance' => $a->balance,
                'available' => $a->availableFunds(),
                'trunks_count' => $a->trunks_count,
            ]);

        return Inertia::render('accounts/Index', [
            'scope' => $carriers ? 'carrier' : 'all',
            'accounts' => $accounts,
            'filters' => (object) ($carriers ? array_diff_key($filters, ['role' => true]) : $filters),
        ]);
    }

    /** New customer (Billing → Accounts) or, at /carriers/create, new carrier. */
    public function create(Request $request): Response
    {
        return Inertia::render('accounts/Create', [...$this->formOptions(), 'role' => self::carrierScope($request) ? 'carrier' : 'customer']);
    }

    public function store(AccountRequest $request): RedirectResponse
    {
        $account = Account::create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => ($account->is_vendor ? 'Carrier' : 'Customer')." {$account->code} created."]);

        return to_route('accounts.show', $account);
    }

    public function show(Account $account): Response
    {
        $account->load(['sellRateDeck:id,name', 'routingPlan:id,name', 'trunks' => fn ($q) => $q->withCount('gateways')]);

        return Inertia::render('accounts/Show', [
            'account' => [
                ...$account->only(['id', 'name', 'code', 'is_customer', 'is_vendor', 'currency', 'credit_limit',
                    'balance', 'vendor_balance', 'timezone', 'billing_email', 'noc_email', 'max_channels', 'max_cps', 'notes']),
                'status' => $account->status->value,
                'billing_type' => $account->billing_type->value,
                'available' => $account->availableFunds(),
                'available_now' => $account->availableFundsNow(),
                'low_balance_threshold' => $account->low_balance_threshold,
                'low_balance_alerted_at' => $account->low_balance_alerted_at,
                'sell_rate_deck' => $account->sellRateDeck?->only(['id', 'name']),
                'routing_plan' => $account->routingPlan?->only(['id', 'name']),
            ],
            'ips' => $account->ips()->orderBy('ip')->get(['id', 'ip', 'tech_prefix', 'description', 'enabled']),
            'trunks' => $account->trunks->map->only(['id', 'name', 'enabled', 'gateways_count']),
            'ledger' => $account->ledgerEntries()->latest('id')->limit(50)
                ->get(['id', 'type', 'amount', 'balance_after', 'description', 'created_at', 'effective_at']),
            'portalUsers' => $account->is_customer
                ? User::query()->where('account_id', $account->id)->orderBy('name')->get(['id', 'name', 'email', 'created_at'])
                : [],
            'timezones' => $account->is_vendor ? timezone_identifiers_list() : [],
            'vendorLedger' => $account->is_vendor
                ? $account->vendorLedgerEntries()->latest('id')->limit(50)->get(['id', 'type', 'amount', 'balance_after', 'description', 'effective_at'])
                : [],
            'vendorInvoices' => $account->is_vendor
                ? $account->vendorInvoices()->orderByDesc('period_start')->limit(24)
                    ->get(['id', 'reference', 'period_start', 'period_end', 'timezone', 'amount', 'our_amount', 'status'])
                    ->map(fn ($i) => [...$i->toArray(), 'difference' => $i->difference()])
                : [],
            'invoices' => $account->is_customer
                ? Invoice::query()->where('account_id', $account->id)->orderByDesc('period_start')->orderByDesc('id')->limit(24)
                    ->get(['id', 'number', 'status', 'period_start', 'period_end', 'timezone', 'currency', 'usage_total', 'amount_due'])
                : [],
        ]);
    }

    public function edit(Account $account): Response
    {
        return Inertia::render('accounts/Edit', [
            ...$this->formOptions(),
            'account' => [
                ...$account->only(['id', 'name', 'code', 'is_customer', 'is_vendor', 'currency', 'credit_limit', 'low_balance_threshold',
                    'timezone', 'billing_email', 'noc_email', 'sell_rate_deck_id', 'routing_plan_id',
                    'max_channels', 'max_cps', 'notes']),
                'status' => $account->status->value,
                'billing_type' => $account->billing_type->value,
            ],
        ]);
    }

    public function update(AccountRequest $request, Account $account): RedirectResponse
    {
        $account->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Account updated.']);

        return to_route('accounts.show', $account);
    }

    /**
     * Route tester: what the routing engine would do with a call from this
     * customer to a number right now (the same function FreeSWITCH calls).
     * Nothing is reserved.
     */
    public function routeTest(Request $request, Account $account, Router $router): JsonResponse
    {
        $data = $request->validate(['number' => ['required', 'string', 'max:64', 'regex:/^[0-9+\s()\-*#]+$/']]);
        $result = $router->route($account, $data['number']);

        $trunks = Trunk::query()->with('account:id,code')->whereIn('id', array_column($result['attempts'], 'trunk_id'))
            ->get(['id', 'name', 'account_id'])->keyBy('id');
        foreach ($result['attempts'] as &$attempt) {
            $trunk = $trunks[$attempt['trunk_id']] ?? null;
            $attempt['trunk'] = $trunk ? "{$trunk->account->code} / {$trunk->name}" : "#{$attempt['trunk_id']}";
            $attempt['margin'] = $result['sell_rate'] !== null && $attempt['buy_rate'] !== null
                ? bcsub((string) $result['sell_rate'], (string) $attempt['buy_rate'], 6) : null;
        }

        return response()->json($result);
    }

    private function formOptions(): array
    {
        return [
            'options' => [
                'statuses' => AccountStatus::values(),
                'billingTypes' => BillingType::values(),
                'rateDecks' => RateDeck::query()->where('kind', 'tariff')->orderBy('name')->get(['id', 'name', 'currency']),
                'routingPlans' => RoutingPlan::query()->orderBy('name')->get(['id', 'name']),
                'timezones' => timezone_identifiers_list(),
                'currencies' => Currencies::options(),
            ],
        ];
    }
}
