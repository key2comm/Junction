<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\RateDeck;
use App\Models\RateImport;
use App\Models\Trunk;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class RateDeckController extends Controller
{
    public function index(): Response
    {
        $now = now();

        return Inertia::render('rate-decks/Index', [
            'decks' => RateDeck::query()
                ->withCount(['rates as current_rates_count' => fn ($q) => $q->effectiveAt($now)])
                ->addSelect([
                    'customers_count' => Account::query()->selectRaw('count(*)')->whereColumn('sell_rate_deck_id', 'rate_decks.id'),
                    'trunks_count' => Trunk::query()->selectRaw('count(*)')->whereColumn('rate_deck_id', 'rate_decks.id'),
                ])
                ->orderBy('name')
                ->get(['id', 'name', 'currency', 'description', 'updated_at']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('rate-decks/Form', ['deck' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $deck = RateDeck::create($this->validated($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Rate deck created. Import a CSV to add rates.']);

        return to_route('rate-decks.show', $deck);
    }

    public function show(Request $request, RateDeck $rateDeck): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:64'],
            'when' => ['nullable', 'in:current,future,expired,all'],
        ]);
        $when = $filters['when'] ?? 'current';
        $now = now();

        $rates = $rateDeck->rates()
            ->when($filters['q'] ?? null, function ($q, $term) {
                $digits = preg_replace('/\D/', '', $term);
                $q->where(fn ($q) => $q
                    ->when($digits !== '', fn ($q) => $q->where('prefix', 'like', $digits.'%'))
                    ->orWhereLike('destination', "%{$term}%", caseSensitive: false));
            })
            ->when($when === 'current', fn ($q) => $q->effectiveAt($now))
            ->when($when === 'future', fn ($q) => $q->where('effective_from', '>', $now))
            ->when($when === 'expired', fn ($q) => $q->whereNotNull('effective_to')->where('effective_to', '<=', $now))
            ->orderBy('prefix')
            ->orderBy('effective_from')
            ->paginate(50)
            ->withQueryString()
            ->through(fn ($r) => $r->only(['id', 'prefix', 'destination', 'rate', 'connect_fee', 'initial_increment', 'increment', 'effective_from', 'effective_to']));

        $imports = $rateDeck->imports()->with('user:id,name')->latest('id')->limit(10)->get();

        return Inertia::render('rate-decks/Show', [
            'deck' => $rateDeck->only(['id', 'name', 'currency', 'description']),
            'rates' => $rates,
            'filters' => ['q' => $filters['q'] ?? '', 'when' => $when],
            'imports' => $imports->map(fn (RateImport $i) => [
                ...$i->only(['id', 'filename', 'mode', 'status', 'stats', 'errors', 'effective_from', 'created_at', 'finished_at']),
                'user' => $i->user?->name,
            ]),
            'importing' => $imports->contains(fn (RateImport $i) => $i->isPending()),
            'usage' => [
                'customers' => Account::query()->where('sell_rate_deck_id', $rateDeck->id)->orderBy('name')->get(['id', 'name', 'code']),
                'trunks' => Trunk::query()->where('rate_deck_id', $rateDeck->id)->with('account:id,code')->get(['id', 'name', 'account_id']),
            ],
        ]);
    }

    public function edit(RateDeck $rateDeck): Response
    {
        return Inertia::render('rate-decks/Form', ['deck' => $rateDeck->only(['id', 'name', 'currency', 'description'])]);
    }

    public function update(Request $request, RateDeck $rateDeck): RedirectResponse
    {
        $rateDeck->update($this->validated($request, $rateDeck));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Rate deck updated.']);

        return to_route('rate-decks.show', $rateDeck);
    }

    private function validated(Request $request, ?RateDeck $deck = null): array
    {
        $request->merge(['currency' => strtoupper(trim((string) $request->input('currency', 'USD')))]);

        return $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('rate_decks', 'name')->ignore($deck)],
            'currency' => ['required', 'regex:/^[A-Z]{3}$/'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
