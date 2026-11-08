<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Transport;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\RateDeck;
use App\Models\Trunk;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Vendor trunks: a vendor's egress product (buy rate deck + gateways).
 */
class TrunkController extends Controller
{
    public function index(Request $request): Response
    {
        $vendor = $request->integer('vendor') ?: null;

        return Inertia::render('trunks/Index', [
            'trunks' => Trunk::query()
                ->with(['account:id,name,code', 'rateDeck:id,name'])
                ->withCount(['gateways', 'routes'])
                ->when($vendor, fn ($q) => $q->where('account_id', $vendor))
                ->join('accounts', 'accounts.id', '=', 'trunks.account_id')
                ->orderBy('accounts.name')->orderBy('trunks.name')
                ->select('trunks.*')
                ->get()
                ->map(fn (Trunk $t) => [
                    ...$t->only(['id', 'name', 'enabled', 'max_channels', 'max_cps', 'gateways_count', 'routes_count']),
                    'vendor' => $t->account->only(['id', 'name', 'code']),
                    'rate_deck' => $t->rateDeck?->only(['id', 'name']),
                ]),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('trunks/Form', [
            'trunk' => null,
            'defaults' => ['account_id' => $request->integer('vendor') ?: null],
            ...$this->options(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $trunk = Trunk::create($this->validated($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Trunk created. Add a gateway next.']);

        return to_route('trunks.show', $trunk);
    }

    public function show(Trunk $trunk): Response
    {
        $trunk->load(['account:id,name,code', 'rateDeck:id,name,currency', 'gateways' => fn ($q) => $q->orderBy('priority')->orderBy('name')]);

        return Inertia::render('trunks/Show', [
            'trunk' => [
                ...$trunk->only(['id', 'name', 'enabled', 'max_channels', 'max_cps', 'tech_prefix', 'strip_digits']),
                'vendor' => $trunk->account->only(['id', 'name', 'code']),
                'rate_deck' => $trunk->rateDeck?->only(['id', 'name', 'currency']),
            ],
            'gateways' => $trunk->gateways->map(fn ($g) => [
                ...$g->only(['id', 'name', 'host', 'port', 'username', 'register', 'enabled', 'priority', 'weight']),
                'transport' => $g->transport->value,
                'has_password' => $g->getRawOriginal('password') !== null,
            ]),
            'routes' => $trunk->routes()->with('routingPlan:id,name')->orderBy('prefix')->limit(100)->get()
                ->map(fn ($r) => [...$r->only(['id', 'prefix', 'priority', 'weight', 'enabled']), 'plan' => $r->routingPlan->only(['id', 'name'])]),
            'transports' => Transport::values(),
            'routes_count' => $trunk->routes()->count(),
        ]);
    }

    public function edit(Trunk $trunk): Response
    {
        return Inertia::render('trunks/Form', [
            'trunk' => $trunk->only(['id', 'account_id', 'name', 'rate_deck_id', 'enabled', 'max_channels', 'max_cps', 'tech_prefix', 'strip_digits']),
            'defaults' => [],
            ...$this->options(),
        ]);
    }

    public function update(Request $request, Trunk $trunk): RedirectResponse
    {
        $trunk->update($this->validated($request, $trunk));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Trunk updated.']);

        return to_route('trunks.show', $trunk);
    }

    /**
     * Delete a carrier trunk, confirmed by typing its name. Its gateways are
     * removed one by one so FreeSWITCH is resynced; the database removes it
     * from LCR groups and its quality stats. Past CDRs are kept (they hold
     * their own rates; their trunk link is cleared).
     */
    public function destroy(Request $request, Trunk $trunk): RedirectResponse
    {
        $request->validate(['confirm' => ['required', 'string']]);
        if (trim($request->string('confirm')) !== trim($trunk->name)) {
            throw ValidationException::withMessages(['confirm' => 'Type the name exactly as shown to confirm.']);
        }

        $account = $trunk->account_id;
        DB::transaction(function () use ($trunk) {
            $trunk->gateways()->get()->each->delete();
            $trunk->delete();
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => "Trunk {$trunk->name} deleted."]);

        return to_route('accounts.show', $account);
    }

    private function options(): array
    {
        return [
            'vendors' => Account::query()->vendors()->orderBy('name')->get(['id', 'name', 'code']),
            'rateDecks' => RateDeck::query()->where('kind', 'carrier')->orderBy('name')->get(['id', 'name', 'currency']),
        ];
    }

    /** Also used by the REST API, so both validate identically. */
    public function validated(Request $request, ?Trunk $trunk = null): array
    {
        $request->merge(['enabled' => $request->boolean('enabled'), 'tech_prefix' => trim((string) $request->input('tech_prefix'))]);

        $data = $request->validate([
            'account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('is_vendor', true)],
            'name' => ['required', 'string', 'max:255',
                Rule::unique('trunks', 'name')->where('account_id', $request->integer('account_id'))->ignore($trunk)],
            'rate_deck_id' => ['nullable', 'integer', Rule::exists('rate_decks', 'id')->where('kind', 'carrier')],
            'enabled' => ['boolean'],
            'max_channels' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'max_cps' => ['nullable', 'numeric', 'min:0.1', 'max:10000'],
            'tech_prefix' => ['nullable', 'string', 'max:16', 'regex:/^[0-9#*]*$/'],
            'strip_digits' => ['nullable', 'integer', 'min:0', 'max:15'],
        ], ['account_id.exists' => 'Choose an account marked as a carrier.', 'rate_deck_id.exists' => 'Choose carrier rates (not a customer tariff).']);

        return [...$data, 'tech_prefix' => $data['tech_prefix'] ?? '', 'strip_digits' => $data['strip_digits'] ?? 0];
    }
}
