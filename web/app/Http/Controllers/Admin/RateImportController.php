<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ImportRates;
use App\Models\RateDeck;
use App\Models\RateImport;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class RateImportController extends Controller
{
    public function store(Request $request, RateDeck $rateDeck): RedirectResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:51200'], // 50 MB
            'mode' => ['required', 'in:update,replace'],
            'effective_from' => ['nullable', 'date'],
        ]);

        $pending = RateImport::query()->where('rate_deck_id', $rateDeck->id)->whereIn('status', ['queued', 'running'])->exists();
        if ($pending) {
            throw ValidationException::withMessages(['file' => 'An import for this deck is already running.']);
        }

        $file = $request->file('file');
        $import = RateImport::create([
            'rate_deck_id' => $rateDeck->id,
            'user_id' => $request->user()->id,
            'filename' => mb_substr($file->getClientOriginalName(), 0, 255),
            'path' => $file->store("rate-imports/{$rateDeck->id}", 'local'),
            'mode' => $data['mode'],
            // Times are UTC; a bare date means midnight UTC.
            'effective_from' => isset($data['effective_from']) ? CarbonImmutable::parse($data['effective_from'], 'UTC') : now(),
        ]);
        ImportRates::dispatch($import);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Importing {$import->filename}…"]);

        return to_route('rate-decks.show', $rateDeck);
    }
}
