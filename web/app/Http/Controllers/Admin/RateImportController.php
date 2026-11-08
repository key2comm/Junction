<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ImportRates;
use App\Models\RateDeck;
use App\Models\RateImport;
use App\Services\RateImporter;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use RuntimeException;

/**
 * Rate CSV imports in two steps: store() reads the uploaded file and shows its
 * columns; start() runs the import with the columns the admin matched.
 */
class RateImportController extends Controller
{
    public function store(Request $request, RateDeck $rateDeck, RateImporter $importer): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:51200']]); // 50 MB

        // Only one file waits for its columns at a time.
        $this->discardWaiting($rateDeck);

        $file = $request->file('file');
        $path = $file->store("rate-imports/{$rateDeck->id}", 'local');
        try {
            $preview = $importer->preview(Storage::disk('local')->path($path));
        } catch (RuntimeException $e) {
            Storage::disk('local')->delete($path);
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }
        if ($preview['header'] === [] || $preview['header'] === ['']) {
            Storage::disk('local')->delete($path);
            throw ValidationException::withMessages(['file' => 'The file is empty.']);
        }

        RateImport::create([
            'rate_deck_id' => $rateDeck->id,
            'user_id' => $request->user()->id,
            'filename' => mb_substr($file->getClientOriginalName(), 0, 255),
            'path' => $path,
            'mode' => 'update',
            'effective_from' => now(),
            'status' => 'mapping',
            'preview' => $preview,
        ]);

        return to_route('rate-decks.show', $rateDeck);
    }

    public function start(Request $request, RateDeck $rateDeck, RateImport $import): RedirectResponse
    {
        abort_unless($import->status === 'mapping', 404);
        $count = max(count($import->preview['header'] ?? []), max(array_map('count', $import->preview['rows'] ?? [[]])));
        $data = $request->validate([
            'columns' => ['required', 'array'],
            'columns.*' => ['nullable', 'integer', 'min:0', 'max:'.max(0, $count - 1)],
            'has_header' => ['boolean'],
            'npa_country' => ['nullable', 'regex:/^[0-9]{1,4}$/'],
            'mode' => ['required', 'in:update,replace'],
            'effective_from' => ['nullable', 'date'],
        ], ['npa_country.regex' => 'The country code is 1 to 4 digits.']);

        $columns = [];
        foreach (array_keys(RateImporter::FIELDS) as $field) {
            $value = $data['columns'][$field] ?? null;
            $columns[$field] = $value === null || $value === '' ? null : (int) $value;
        }
        if ($columns['rate'] === null) {
            throw ValidationException::withMessages(['columns.rate' => 'Choose the column with the rate.']);
        }
        if ($columns['prefix'] === null && $columns['npa'] === null) {
            throw ValidationException::withMessages(['columns.prefix' => 'Choose the column with the prefix, or the NPA (and NXX).']);
        }
        $used = array_filter($columns, fn ($i) => $i !== null);
        if (count($used) !== count(array_unique($used))) {
            throw ValidationException::withMessages(['columns' => 'Each file column can only be used for one field.']);
        }
        if ($rateDeck->imports()->whereIn('status', ['queued', 'running'])->exists()) {
            throw ValidationException::withMessages(['columns' => 'An import for this deck is already running.']);
        }

        $import->update([
            'mapping' => ['has_header' => $request->boolean('has_header'), 'columns' => $columns, 'npa_country' => $data['npa_country'] ?? '1'],
            'mode' => $data['mode'],
            // Times are UTC; a bare date means midnight UTC.
            'effective_from' => isset($data['effective_from']) ? CarbonImmutable::parse($data['effective_from'], 'UTC') : now(),
            'status' => 'queued',
        ]);
        ImportRates::dispatch($import);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Importing {$import->filename}…"]);

        return to_route('rate-decks.show', $rateDeck);
    }

    public function cancel(RateDeck $rateDeck, RateImport $import): RedirectResponse
    {
        abort_unless($import->status === 'mapping', 404);
        Storage::disk('local')->delete($import->path);
        $import->delete();

        return to_route('rate-decks.show', $rateDeck);
    }

    private function discardWaiting(RateDeck $rateDeck): void
    {
        foreach ($rateDeck->imports()->where('status', 'mapping')->get() as $waiting) {
            Storage::disk('local')->delete($waiting->path);
            $waiting->delete();
        }
    }
}
