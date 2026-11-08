<?php

namespace App\Http\Controllers\Admin;

use App\Enums\RateDeckKind;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\RateDeck;
use App\Models\RateImport;
use App\Models\Trunk;
use App\Services\RateImporter;
use App\Support\Currencies;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tariffs (what customers pay) and carrier rates (what carriers charge us):
 * both are rate decks, listed separately by kind.
 */
class RateDeckController extends Controller
{
    /** /tariffs and /carrier-rates (the route supplies the kind). */
    public function index(Request $request): Response
    {
        $kind = RateDeckKind::from($request->route('kind'));
        $now = now();

        return Inertia::render('rate-decks/Index', [
            'kind' => $kind->value,
            'decks' => RateDeck::query()->where('kind', $kind)
                ->withCount(['rates as current_rates_count' => fn ($q) => $q->effectiveAt($now)])
                ->addSelect([
                    'customers_count' => Account::query()->selectRaw('count(*)')->whereColumn('sell_rate_deck_id', 'rate_decks.id'),
                    'trunks_count' => Trunk::query()->selectRaw('count(*)')->whereColumn('rate_deck_id', 'rate_decks.id'),
                ])
                ->orderBy('name')
                ->get(['id', 'name', 'currency', 'description', 'updated_at']),
        ]);
    }

    public function create(Request $request): Response
    {
        $kind = RateDeckKind::tryFrom((string) $request->query('kind')) ?? RateDeckKind::Carrier;

        return Inertia::render('rate-decks/Form', ['deck' => null, 'kind' => $kind->value, 'canChangeKind' => true, 'currencies' => Currencies::options()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $deck = RateDeck::create($this->validated($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => $deck->kind === RateDeckKind::Tariff
            ? 'Tariff created. Build it from your Carrier Rates with a markup, or import a CSV.'
            : 'Carrier Rates created. Import a CSV to add rates.']);

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

        $imports = $rateDeck->imports()->where('status', '!=', 'mapping')->with('user:id,name')->latest('id')->limit(10)->get();
        $waiting = $rateDeck->imports()->where('status', 'mapping')->latest('id')->first();

        return Inertia::render('rate-decks/Show', [
            'deck' => [...$rateDeck->only(['id', 'name', 'currency', 'description']), 'kind' => $rateDeck->kind->value],
            'rates' => $rates,
            'filters' => ['q' => $filters['q'] ?? '', 'when' => $when],
            'imports' => $imports->map(fn (RateImport $i) => [
                ...$i->only(['id', 'filename', 'mode', 'status', 'stats', 'errors', 'effective_from', 'created_at', 'finished_at']),
                'user' => $i->user?->name,
            ]),
            'importing' => $imports->contains(fn (RateImport $i) => $i->isPending()),
            // An uploaded file waiting for its columns to be matched (step 2 of an import).
            'mapping' => $waiting ? [...$waiting->only(['id', 'filename', 'preview']), 'fields' => RateImporter::FIELDS] : null,
            // Tariffs: built from Carrier Rates plus a markup.
            'build' => $rateDeck->kind === RateDeckKind::Tariff ? [
                'sources' => $rateDeck->sources()->pluck('rate_decks.id'),
                'markup_type' => $rateDeck->markup_type ?? 'percent',
                'markup_value' => $rateDeck->markup_value,
                'built_at' => $rateDeck->built_at,
                'carrier_rates' => RateDeck::query()->where('kind', RateDeckKind::Carrier)->orderBy('name')->get(['id', 'name', 'currency']),
            ] : null,
            'usage' => [
                'customers' => Account::query()->where('sell_rate_deck_id', $rateDeck->id)->orderBy('name')->get(['id', 'name', 'code']),
                'trunks' => Trunk::query()->where('rate_deck_id', $rateDeck->id)->with('account:id,code')->get(['id', 'name', 'account_id']),
            ],
        ]);
    }

    public function edit(RateDeck $rateDeck): Response
    {
        return Inertia::render('rate-decks/Form', [
            'deck' => $rateDeck->only(['id', 'name', 'currency', 'description']),
            'kind' => $rateDeck->kind->value,
            'canChangeKind' => ! $rateDeck->inUse(),
            'currencies' => Currencies::options(),
        ]);
    }

    public function update(Request $request, RateDeck $rateDeck): RedirectResponse
    {
        $rateDeck->update($this->validated($request, $rateDeck));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Saved.']);

        return to_route('rate-decks.show', $rateDeck);
    }

    /** Also used by the REST API, so both validate identically. */
    public function validated(Request $request, ?RateDeck $deck = null): array
    {
        $request->merge(['currency' => strtoupper(trim((string) $request->input('currency', Currencies::DEFAULT)))]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('rate_decks', 'name')->ignore($deck)],
            'kind' => [$deck ? 'sometimes' : 'required', Rule::enum(RateDeckKind::class)],
            'currency' => ['required', Rule::in(array_keys(Currencies::ALL))],
            'description' => ['nullable', 'string', 'max:2000'],
        ], ['kind.required' => 'Choose tariff or carrier rates.', 'currency.in' => 'Choose a currency from the list.']);

        // A deck in use keeps its kind (customers use tariffs, trunks use carrier rates).
        if ($deck && isset($data['kind']) && $data['kind'] !== $deck->kind->value && $deck->inUse()) {
            throw ValidationException::withMessages(['kind' => "This is in use, so it can't change between tariff and carrier rates."]);
        }

        return $data;
    }
}
