<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\PbxDid;
use App\Services\DidImporter;
use App\Support\Languages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Routing -> DIDs -> Import: upload a CSV, match its columns to the DID
 * fields, then import (see DidImporter). The uploaded file waits in private
 * storage between the two steps; its path is kept in the admin's session.
 */
class DidImportController extends Controller
{
    private const SESSION = 'did_import';

    private const DIR = 'did-imports';

    public function show(Request $request, DidImporter $importer): Response
    {
        $upload = $this->pending($request);

        return Inertia::render('dids/Import', [
            'upload' => $upload ? [
                'filename' => $upload['filename'],
                'preview' => $importer->preview(Storage::disk('local')->path($upload['path'])),
            ] : null,
            'fields' => DidImporter::FIELDS,
            'maxRows' => DidImporter::MAX_ROWS,
            'customers' => Account::query()->where('is_customer', true)->orderBy('name')->get(['id', 'name', 'code']),
            'providers' => Account::query()->where('is_vendor', true)->orderBy('name')->get(['id', 'name', 'code']),
            'languages' => Languages::options(),
            'statuses' => PbxDid::STATUSES,
        ]);
    }

    public function sample(): StreamedResponse
    {
        return response()->streamDownload(fn () => print (DidImporter::SAMPLE), 'dids-sample.csv', ['Content-Type' => 'text/csv']);
    }

    /** The upload waiting in this session, if its file is still there. */
    private function pending(Request $request): ?array
    {
        $upload = $request->session()->get(self::SESSION);

        return is_array($upload) && Storage::disk('local')->exists($upload['path'] ?? '') ? $upload : null;
    }

    public function upload(Request $request): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:5120', 'mimes:csv,txt']], ['file.mimes' => 'Upload a CSV file.']);
        $this->forget($request);
        $file = $request->file('file');
        $path = $file->storeAs(self::DIR, Str::random(32).'.csv', 'local');
        $request->session()->put(self::SESSION, ['path' => $path, 'filename' => mb_substr($file->getClientOriginalName(), 0, 255)]);

        return to_route('did-import.show');
    }

    public function store(Request $request, DidImporter $importer): RedirectResponse
    {
        $upload = $this->pending($request);
        if (! $upload) {
            Inertia::flash('toast', ['type' => 'warning', 'message' => 'Upload the file again: the earlier upload has expired.']);

            return to_route('did-import.show');
        }
        $data = $request->validate([
            'has_header' => ['boolean'],
            'add_one' => ['boolean'],
            'existing' => ['required', Rule::in(['skip', 'update'])],
            'columns' => ['required', 'array'],
            'columns.*' => ['nullable', 'integer', 'min:0', 'max:200'],
            'fallback' => ['array'],
            'fallback.account_id' => ['nullable', 'integer'],
            'fallback.provider_account_id' => ['nullable', 'integer'],
            'fallback.device_id' => ['nullable', 'integer'],
            'fallback.language' => ['nullable', Rule::in(array_keys(Languages::ALL))],
            'fallback.status' => ['nullable', Rule::in(array_keys(PbxDid::STATUSES))],
        ]);
        $columns = array_intersect_key($data['columns'], DidImporter::FIELDS);

        $result = $importer->run(Storage::disk('local')->path($upload['path']), [
            'has_header' => $request->boolean('has_header'),
            'add_one' => $request->boolean('add_one'),
            'existing' => $data['existing'],
            'columns' => array_map(fn ($v) => $v === null ? null : (int) $v, $columns),
            'defaults' => $data['fallback'] ?? [],
        ]);
        $this->forget($request);

        $parts = array_filter([
            $result['created'] ? "{$result['created']} added" : null,
            $result['updated'] ? "{$result['updated']} updated" : null,
            $result['skipped'] ? "{$result['skipped']} already there (skipped)" : null,
        ]);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'DIDs imported: '.implode(', ', $parts ?: ['nothing to do']).'.']);

        return to_route('dids.index');
    }

    public function cancel(Request $request): RedirectResponse
    {
        $this->forget($request);

        return to_route('did-import.show');
    }

    private function forget(Request $request): void
    {
        $upload = $request->session()->pull(self::SESSION);
        if (is_array($upload) && isset($upload['path'])) {
            Storage::disk('local')->delete($upload['path']);
        }
    }
}
