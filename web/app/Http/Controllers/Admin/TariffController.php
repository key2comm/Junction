<?php

namespace App\Http\Controllers\Admin;

use App\Enums\RateDeckKind;
use App\Http\Controllers\Controller;
use App\Jobs\BuildTariff;
use App\Models\Rate;
use App\Models\RateDeck;
use App\Models\RateImport;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Tariffs: build from Carrier Rates plus a markup (% or an amount per minute),
 * and change a single prefix's rate afterwards.
 */
class TariffController extends Controller
{
    public function build(Request $request, RateDeck $rateDeck): RedirectResponse
    {
        abort_unless($rateDeck->kind === RateDeckKind::Tariff, 404);
        $data = $request->validate([
            'sources' => ['required', 'array', 'min:1'],
            'sources.*' => ['integer', Rule::exists('rate_decks', 'id')->where('kind', 'carrier')],
            'markup_type' => ['required', 'in:percent,fixed'],
            'markup_value' => ['required', 'numeric', 'min:0', $request->input('markup_type') === 'percent' ? 'max:1000' : 'max:100'],
            'effective_from' => ['nullable', 'date'],
        ], [
            'sources.required' => 'Choose at least one Carrier Rates list.',
            'sources.*.exists' => 'Choose Carrier Rates only.',
        ]);

        $sources = RateDeck::query()->whereIn('id', $data['sources'])->get(['id', 'name', 'currency']);
        if ($wrong = $sources->firstWhere('currency', '!=', $rateDeck->currency)) {
            throw ValidationException::withMessages(['sources' => "{$wrong->name} is in {$wrong->currency}, but this tariff is in {$rateDeck->currency}."]);
        }
        if (RateImport::query()->where('rate_deck_id', $rateDeck->id)->whereIn('status', ['queued', 'running'])->exists()) {
            throw ValidationException::withMessages(['sources' => 'An import or build for this tariff is already running.']);
        }

        $rateDeck->update(['markup_type' => $data['markup_type'], 'markup_value' => $data['markup_value']]);
        $rateDeck->sources()->sync($sources->pluck('id'));

        $markup = $data['markup_type'] === 'percent'
            ? rtrim(rtrim(number_format((float) $data['markup_value'], 4, '.', ''), '0'), '.').'%'
            : $rateDeck->currency.' '.number_format((float) $data['markup_value'], 4, '.', '').'/min';
        $import = RateImport::create([
            'rate_deck_id' => $rateDeck->id,
            'user_id' => $request->user()->id,
            'filename' => mb_substr('Built from '.$sources->pluck('name')->join(', ')." + {$markup}", 0, 255),
            'path' => '',
            'mode' => 'build',
            'effective_from' => isset($data['effective_from']) ? CarbonImmutable::parse($data['effective_from'], 'UTC') : now(),
        ]);
        BuildTariff::dispatch($import);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Building the tariff…']);

        return to_route('rate-decks.show', $rateDeck);
    }

    /**
     * Change one prefix's rate. A rate already in effect ends now and the new
     * one starts now, so calls already made keep the price they were rated at;
     * a rate that hasn't started yet is simply corrected.
     */
    public function updateRate(Request $request, RateDeck $rateDeck, Rate $rate): RedirectResponse
    {
        $data = $request->validate([
            'rate' => ['required', 'numeric', 'min:0', 'max:999999'],
            'connect_fee' => ['nullable', 'numeric', 'min:0', 'max:999999'],
        ]);
        if ($rate->effective_to !== null && $rate->effective_to->lte(now())) {
            throw ValidationException::withMessages(['rate' => 'This rate has already ended; change the one in effect.']);
        }
        if (RateImport::query()->where('rate_deck_id', $rateDeck->id)->whereIn('status', ['queued', 'running'])->exists()) {
            throw ValidationException::withMessages(['rate' => 'An import or build is running; try again when it finishes.']);
        }
        $changes = ['rate' => $data['rate'], 'connect_fee' => $data['connect_fee'] ?? $rate->connect_fee];

        DB::transaction(function () use ($rate, $changes) {
            if ($rate->effective_from->isFuture()) {
                $rate->update($changes);

                return;
            }
            $now = now();
            $new = $rate->replicate(['created_at', 'updated_at']);
            $new->fill([...$changes, 'effective_from' => $now, 'effective_to' => $rate->effective_to]);
            $rate->update(['effective_to' => $now]);
            $new->save();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => "Rate for {$rate->prefix} changed."]);

        return back();
    }
}
