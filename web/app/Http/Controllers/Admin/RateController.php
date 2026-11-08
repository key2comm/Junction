<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RateDeck;
use App\Models\RateImport;
use App\Services\RateImporter;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Adding one rate by hand (a new prefix, or a new rate for an existing one),
 * applied exactly like a one-row "update" import: the rate in effect ends
 * when the new one starts, and anything scheduled after it is replaced.
 */
class RateController extends Controller
{
    public function store(Request $request, RateDeck $rateDeck, RateImporter $importer): RedirectResponse
    {
        $data = $request->validate([
            'prefix' => ['required', 'string', 'regex:/^[0-9]{1,32}$/'],
            'destination' => ['nullable', 'string', 'max:255'],
            'rate' => ['required', 'numeric', 'min:0', 'max:999999'],
            'connect_fee' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'initial_increment' => ['required', 'integer', 'min:1', 'max:3600'],
            'increment' => ['required', 'integer', 'min:1', 'max:3600'],
            'effective_from' => ['nullable', 'date'],
        ], ['prefix.regex' => 'The prefix is 1 to 32 digits, e.g. 1201555 or 44.']);
        if ($rateDeck->imports()->whereIn('status', ['queued', 'running'])->exists()) {
            throw ValidationException::withMessages(['prefix' => 'An import is running for this list; try again when it finishes.']);
        }

        // Times are UTC; blank means now.
        $effective = isset($data['effective_from']) ? CarbonImmutable::parse($data['effective_from'], 'UTC') : CarbonImmutable::now();
        $at = $effective->utc()->format('Y-m-d H:i:sP');
        $row = [
            'prefix' => $data['prefix'],
            'destination' => ($data['destination'] ?? '') === '' ? null : $data['destination'],
            'rate' => (string) $data['rate'],
            'connect_fee' => (string) ($data['connect_fee'] ?? '0'),
            'initial_increment' => (int) $data['initial_increment'],
            'increment' => (int) $data['increment'],
            'effective_from' => $at,
        ];

        // Not saved: only describes the change for RateImporter::apply().
        $change = new RateImport(['rate_deck_id' => $rateDeck->id, 'mode' => 'update', 'effective_from' => $at]);
        $stats = $importer->apply($change, function () use ($row) {
            DB::table('rate_import_rows')->insert($row);

            return 1;
        });

        $message = match (true) {
            $stats['unchanged'] > 0 => "{$row['prefix']} already has exactly that rate; nothing changed.",
            $stats['changed'] > 0 => "New rate for {$row['prefix']} saved; the old one ends ".($effective->isFuture() ? 'then' : 'now').'.',
            default => "Rate for {$row['prefix']} added.",
        };
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
