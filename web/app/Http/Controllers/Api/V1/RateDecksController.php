<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Admin\RateDeckController;
use App\Http\Controllers\Controller;
use App\Jobs\ImportRates;
use App\Models\RateDeck;
use App\Models\RateImport;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class RateDecksController extends Controller
{
    use Paginates;

    public function index(Request $request): JsonResponse
    {
        $request->validate(['kind' => ['nullable', 'in:tariff,carrier']]);

        return $this->paged(RateDeck::query()->when($request->query('kind'), fn ($q, $k) => $q->where('kind', $k))
            ->orderBy('id')->paginate($this->perPage($request)), fn ($d) => Present::deck($d));
    }

    public function show(RateDeck $rateDeck): JsonResponse
    {
        return response()->json(['data' => Present::deck($rateDeck)]);
    }

    public function store(Request $request, RateDeckController $admin): JsonResponse
    {
        $deck = RateDeck::create($admin->validated($request));

        return response()->json(['data' => Present::deck($deck)], 201);
    }

    public function update(Request $request, RateDeck $rateDeck, RateDeckController $admin): JsonResponse
    {
        $request->replace([...$rateDeck->only(['name', 'currency', 'description']), 'kind' => $rateDeck->kind->value, ...$request->all()]);
        $rateDeck->update($admin->validated($request, $rateDeck));

        return response()->json(['data' => Present::deck($rateDeck->fresh())]);
    }

    /** Rates, filtered by prefix and time: ?prefix=44&at=2026-12-01T00:00:00Z (default: in effect now). */
    public function rates(Request $request, RateDeck $rateDeck): JsonResponse
    {
        $f = $request->validate([
            'prefix' => ['nullable', 'string', 'max:32', 'regex:/^[0-9]+$/'],
            'at' => ['nullable', 'date'],
            'all' => ['nullable', 'boolean'],
        ]);
        $page = $rateDeck->rates()
            ->when($f['prefix'] ?? null, fn ($q, $p) => $q->where('prefix', 'like', "{$p}%"))
            ->when(! $request->boolean('all'), fn ($q) => $q->effectiveAt(isset($f['at']) ? CarbonImmutable::parse($f['at']) : now()))
            ->orderBy('prefix')->orderBy('effective_from')
            ->paginate($this->perPage($request));

        return $this->paged($page, fn ($r) => Present::rate($r));
    }

    /** Upload a rate deck CSV (multipart "file"); processed in the background. Poll the import for its result. */
    public function storeImport(Request $request, RateDeck $rateDeck): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:51200'],
            'mode' => ['required', 'in:update,replace'],
            'effective_from' => ['nullable', 'date'],
        ]);
        if ($rateDeck->imports()->whereIn('status', ['queued', 'running'])->exists()) {
            throw ValidationException::withMessages(['file' => 'An import for this deck is already running.']);
        }

        $file = $request->file('file');
        $import = RateImport::create([
            'rate_deck_id' => $rateDeck->id,
            'user_id' => $request->user()?->id,
            'filename' => mb_substr($file->getClientOriginalName(), 0, 255),
            'path' => $file->store("rate-imports/{$rateDeck->id}", 'local'),
            'mode' => $data['mode'],
            'effective_from' => isset($data['effective_from']) ? CarbonImmutable::parse($data['effective_from'], 'UTC') : now(),
        ]);
        ImportRates::dispatch($import);

        return response()->json(['data' => Present::import($import->fresh())], 202);
    }

    public function showImport(RateDeck $rateDeck, RateImport $import): JsonResponse
    {
        return response()->json(['data' => Present::import($import)]);
    }
}
