<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AccountStatus;
use App\Enums\BillingType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AccountRequest;
use App\Enums\Transport;
use App\Models\Account;
use App\Models\Endpoint;
use App\Models\Invoice;
use App\Models\RateDeck;
use App\Models\RoutingPlan;
use App\Models\Trunk;
use App\Models\User;
use App\Services\Router;
use App\Support\ClientDefaults;
use App\Support\Currencies;
use App\Support\DialCountries;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
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
            ->withCount('gateways')
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
                'gateways_count' => $a->gateways_count,
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
        $carrier = self::carrierScope($request);

        return Inertia::render('accounts/Create', [
            ...$this->formOptions(),
            'role' => $carrier ? 'carrier' : 'customer',
            // Settings → Client defaults pre-fill a new customer (carriers keep the built-in values).
            'defaults' => $carrier ? null : ClientDefaults::get(),
        ]);
    }

    public function store(AccountRequest $request): RedirectResponse
    {
        $data = $request->validated();
        // New customers may call Canada and the USA only, unless the form says otherwise.
        $data['dialing_scope'] ??= 'nanp';
        $carrier = $data['is_vendor'] ? $this->carrierSettings($request) : null;
        $account = DB::transaction(function () use ($data, $carrier) {
            $account = Account::create($data);
            if ($carrier !== null) {
                $account->ensureCarrierTrunk()->update(['name' => $account->name, ...$carrier]);
            }

            return $account;
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => $account->is_vendor
            ? "Carrier {$account->code} created. Add its SIP trunks next."
            : "Customer {$account->code} created."]);

        return to_route('accounts.show', $account);
    }

    public function show(Account $account): Response
    {
        $account->load(['sellRateDeck:id,name', 'routingPlan:id,name']);

        return Inertia::render('accounts/Show', [
            'account' => [
                ...$account->only(['id', 'name', 'code', 'is_customer', 'is_vendor', 'currency', 'credit_limit',
                    'balance', 'vendor_balance', 'timezone', 'billing_email', 'noc_email', 'max_channels', 'max_cps', 'notes',
                    'dialing_scope', 'dialing_countries']),
                'status' => $account->status->value,
                'billing_type' => $account->billing_type->value,
                'available' => $account->availableFunds(),
                'available_now' => $account->availableFundsNow(),
                'low_balance_threshold' => $account->low_balance_threshold,
                'low_balance_alerted_at' => $account->low_balance_alerted_at,
                'sell_rate_deck' => $account->sellRateDeck?->only(['id', 'name']),
                'routing_plan' => $account->routingPlan?->only(['id', 'name']),
                'dialing_country_names' => collect($account->dialing_countries ?? [])
                    ->map(fn ($code) => DialCountries::ALL[$code][0] ?? $code)->sort()->values(),
            ],
            'ips' => $account->ips()->orderBy('ip')->get(['id', 'ip', 'tech_prefix', 'description', 'enabled']),
            'carrier' => $account->is_vendor ? $this->carrierShow($account) : null,
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

    /** The carrier page: its settings and SIP trunks. */
    private function carrierShow(Account $account): array
    {
        $trunk = $account->carrierTrunk?->load('rateDeck:id,name,currency');
        $gateways = $trunk ? $trunk->gateways()->orderBy('priority')->orderBy('id')->get() : collect();

        return [
            'trunk_id' => $trunk?->id,
            'rate_deck' => $trunk?->rateDeck?->only(['id', 'name', 'currency']),
            'max_inbound' => $trunk?->max_inbound,
            'max_outbound' => $trunk?->max_channels,
            'outbound_cps' => $trunk?->max_cps,
            'codecs' => array_map(fn ($c) => Endpoint::CODECS[$c] ?? $c, $trunk?->codecs ?? []),
            'tech_prefix' => $trunk?->tech_prefix ?? '',
            'strip_digits' => $trunk?->strip_digits ?? 0,
            'routes_count' => $trunk ? $trunk->routes()->count() : 0,
            'sip_trunks' => $gateways->map(fn ($g) => [
                ...$g->only(['id', 'label', 'name', 'host', 'port', 'username', 'from_user', 'from_domain', 'outbound_proxy',
                    'send_rpid', 'send_pai', 'register', 'enabled', 'priority', 'weight']),
                'transport' => $g->transport->value,
                'has_password' => $g->getRawOriginal('password') !== null,
            ])->values(),
            'transports' => Transport::values(),
        ];
    }

    public function edit(Account $account): Response
    {
        return Inertia::render('accounts/Edit', [
            ...$this->formOptions(),
            'account' => [
                ...$account->only(['id', 'name', 'code', 'is_customer', 'is_vendor', 'currency', 'credit_limit', 'low_balance_threshold',
                    'timezone', 'billing_email', 'noc_email', 'sell_rate_deck_id', 'routing_plan_id',
                    'max_channels', 'max_cps', 'notes', 'dialing_scope', 'dialing_countries']),
                'status' => $account->status->value,
                'billing_type' => $account->billing_type->value,
                ...($account->is_vendor ? self::carrierValues($account) : []),
            ],
        ]);
    }

    /** A carrier's settings for the form (kept on its routing record). */
    private static function carrierValues(Account $account): array
    {
        $t = $account->carrierTrunk;

        return [
            'carrier_rate_deck_id' => $t?->rate_deck_id,
            'max_inbound' => $t?->max_inbound,
            'max_outbound' => $t?->max_channels,
            'outbound_cps' => $t?->max_cps,
            'codecs' => $t?->codecs ?? ['PCMU', 'PCMA'],
            'tech_prefix' => $t?->tech_prefix ?? '',
            'strip_digits' => $t?->strip_digits ?? 0,
        ];
    }

    /** Validates a carrier's settings; returns the routing record's columns. */
    private function carrierSettings(Request $request): array
    {
        $request->merge(['tech_prefix' => trim((string) $request->input('tech_prefix'))]);
        $data = $request->validate([
            'carrier_rate_deck_id' => ['nullable', 'integer', Rule::exists('rate_decks', 'id')->where('kind', 'carrier')],
            'max_inbound' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'max_outbound' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'outbound_cps' => ['nullable', 'numeric', 'min:0.1', 'max:10000'],
            'codecs' => ['required', 'array', 'min:1'],
            'codecs.*' => ['string', Rule::in(array_keys(Endpoint::CODECS))],
            'tech_prefix' => ['nullable', 'string', 'max:16', 'regex:/^[0-9#*]*$/'],
            'strip_digits' => ['nullable', 'integer', 'min:0', 'max:15'],
        ], [
            'carrier_rate_deck_id.exists' => 'Choose carrier rates (not a customer tariff).',
            'codecs.required' => 'Choose at least one codec.',
        ]);

        return [
            'rate_deck_id' => $data['carrier_rate_deck_id'] ?? null,
            'max_inbound' => $data['max_inbound'] ?? null,
            'max_channels' => $data['max_outbound'] ?? null,
            'max_cps' => $data['outbound_cps'] ?? null,
            // Offered in this order (PCMU first).
            'codecs' => array_values(array_intersect(array_keys(Endpoint::CODECS), $data['codecs'])),
            'tech_prefix' => $data['tech_prefix'] ?? '',
            'strip_digits' => $data['strip_digits'] ?? 0,
        ];
    }

    public function update(AccountRequest $request, Account $account): RedirectResponse
    {
        $data = $request->validated();
        $carrier = $data['is_vendor'] ? $this->carrierSettings($request) : null;
        DB::transaction(function () use ($account, $data, $carrier) {
            $account->update($data);
            if ($carrier !== null) {
                $account->ensureCarrierTrunk()->update(['name' => $account->name, ...$carrier]);
            }
        });

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
                'carrierRates' => RateDeck::query()->where('kind', 'carrier')->orderBy('name')->get(['id', 'name', 'currency']),
                'codecs' => Endpoint::CODECS,
                'countries' => DialCountries::selectable(),
            ],
        ];
    }
}
