<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Endpoint;
use App\Models\PbxDid;
use App\Services\Pbx;
use App\Support\Languages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Routing -> DIDs: numbers providers (carriers) deliver to us, each sent to a
 * destination of the customer that owns it: one of their PBX/Devices, or an
 * extension, IVR or voicemail on the built-in PBX. Read live by the call path.
 */
class DidController extends Controller
{
    public function __construct(private Pbx $pbx) {}

    public function index(Request $request): Response
    {
        $filters = $this->filters($request);
        $dids = $this->query($filters)->paginate(50)->withQueryString();

        $dids->through(function (PbxDid $d) {
            return [
                ...$d->only(['id', 'number', 'language', 'status', 'status_reason', 'description']),
                'account' => $d->account?->only(['id', 'name', 'code']),
                'provider' => $d->provider?->only(['id', 'name', 'code']),
                'destination' => $d->account ? $this->pbx->describe($d->account, $d->destination) : null,
            ];
        });

        return Inertia::render('dids/Index', [
            'dids' => $dids,
            'filters' => ['q' => $filters['q'] ?? '', 'in' => $filters['in'] ?? '', 'account' => $filters['account'] ?? null, 'provider' => $filters['provider'] ?? null, 'status' => $filters['status'] ?? '',
                'endpoint' => ($id = $filters['endpoint'] ?? null) ? Endpoint::query()->whereKey($id)->first(['id', 'kind', 'name'])?->only(['id', 'kind', 'name']) : null],
            'customers' => $this->customers(),
            'providers' => $this->providers(),
            'statuses' => PbxDid::STATUSES,
            'searchIn' => self::SEARCH_IN,
            'counts' => PbxDid::query()->selectRaw('status, count(*) AS n')->groupBy('status')->pluck('n', 'status'),
        ]);
    }

    /**
     * Routing → DIDs → Export: every DID matching the list's filters, as CSV, in the
     * import's columns (so an export can be edited and imported back), plus the
     * names and a readable destination.
     */
    public function export(Request $request): StreamedResponse
    {
        $query = $this->query($this->filters($request));

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['number', 'customer', 'customer_name', 'provider', 'provider_name', 'device_id', 'extension', 'destination',
                'language', 'status', 'status_reason', 'comment', 'created_at', 'updated_at']);
            foreach ($query->lazy(500) as $d) {
                $dest = $d->destination ?? [];
                fputcsv($out, [
                    $d->number, $d->account?->code, $d->account?->name, $d->provider?->code, $d->provider?->name,
                    ($dest['type'] ?? null) === 'endpoint' ? $dest['target'] : null,
                    ($dest['type'] ?? null) === 'number' ? $dest['target'] : null,
                    $d->account ? $this->pbx->describe($d->account, $d->destination) : null,
                    $d->language, $d->status, $d->status_reason, $d->description,
                    $d->created_at?->toIso8601String(), $d->updated_at?->toIso8601String(),
                ]);
            }
            fclose($out);
        }, 'dids-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    /** Where the list's search looks (none: all of them). */
    public const SEARCH_IN = ['number' => 'Number', 'comment' => 'Comment', 'customer' => 'Customer name'];

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        return $request->validate([
            'q' => ['nullable', 'string', 'max:64'],
            'in' => ['nullable', Rule::in(array_keys(self::SEARCH_IN))],
            'account' => ['nullable', 'integer'],
            'provider' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(array_keys(PbxDid::STATUSES))],
            'endpoint' => ['nullable', 'integer'],
        ]);
    }

    /** The list's filters (search, customer, provider, status, PBX/Device), sorted by number. */
    private function query(array $filters)
    {
        $text = trim((string) ($filters['q'] ?? ''));
        $digits = preg_replace('/\D/', '', $text);
        $like = '%'.addcslashes($text, '%_\\').'%';
        $in = $filters['in'] ?? null;
        $field = fn (string $f) => ! $in || $in === $f;

        return PbxDid::query()->with(['account:id,name,code', 'provider:id,name,code'])
            ->when($text !== '', fn ($q) => $q->where(fn ($w) => $w
                // Numbers: by their digits, when the text looks like a number ("416-555 0100"), so a
                // customer code like ACME02 doesn't also match every number with 02 in it.
                ->when($field('number'), fn ($x) => $digits !== '' && preg_match('/^[\d\s()+.\-]+$/', $text)
                    ? $x->orWhere('number', 'like', "%{$digits}%") : $x->orWhereRaw('false'))
                ->when($field('comment'), fn ($x) => $x->orWhere('description', 'ilike', $like))
                ->when($field('customer'), fn ($x) => $x->orWhereHas('account', fn ($a) => $a->where(fn ($n) => $n->where('name', 'ilike', $like)->orWhere('code', 'ilike', $like))))))
            ->when($filters['account'] ?? null, fn ($q, $id) => $q->where('account_id', $id))
            ->when($filters['provider'] ?? null, fn ($q, $id) => $q->where('provider_account_id', $id))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['endpoint'] ?? null, fn ($q, $id) => $q->where('destination->type', 'endpoint')->where('destination->target', (string) $id))
            ->orderBy('number');
    }

    public function create(Request $request): Response
    {
        return Inertia::render('dids/Form', [
            'did' => null,
            'defaults' => ['account_id' => $request->integer('account') ?: null, 'language' => Languages::DEFAULT],
            ...$this->options(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $did = PbxDid::create($this->validated($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => "DID {$did->number} added."]);

        return to_route('dids.index');
    }

    public function edit(PbxDid $did): Response
    {
        return Inertia::render('dids/Form', [
            'did' => $did->only(['id', 'account_id', 'provider_account_id', 'number', 'destination', 'language', 'status', 'status_reason', 'description']),
            'defaults' => [],
            ...$this->options(),
        ]);
    }

    public function update(Request $request, PbxDid $did): RedirectResponse
    {
        $did->update($this->validated($request, $did));
        Inertia::flash('toast', ['type' => 'success', 'message' => "DID {$did->number} saved."]);

        return to_route('dids.index');
    }

    public function destroy(PbxDid $did): RedirectResponse
    {
        $did->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => "DID {$did->number} deleted."]);

        return to_route('dids.index');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?PbxDid $did = null): array
    {
        $request->merge(['number' => preg_replace('/\D/', '', (string) $request->input('number')), 'language' => strtolower((string) $request->input('language'))]);
        $data = $request->validate([
            'number' => ['required', 'string', 'regex:/^[0-9]{7,20}$/', Rule::unique('pbx_dids', 'number')->ignore($did)],
            // A Free DID has no customer and no destination; only the provider is needed.
            'account_id' => [$request->input('status') === 'free' ? 'nullable' : 'required', 'integer', Rule::exists('accounts', 'id')->where('is_customer', true)],
            'provider_account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('is_vendor', true)],
            'language' => [$request->input('status') === 'free' ? 'nullable' : 'required', Rule::in(array_keys(Languages::ALL))],
            'status' => ['required', Rule::in(array_keys(PbxDid::STATUSES))],
            'status_reason' => [$request->input('status') === 'banned' ? 'required' : 'nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
        ], [
            'number.regex' => 'Enter the full number as the provider delivers it, in international format (7-20 digits), e.g. 14165551234.',
            'number.unique' => 'That number is already assigned.',
            'provider_account_id.required' => 'Choose the provider (the carrier the number comes from).',
            'provider_account_id.exists' => 'Choose one of your carriers.',
            'status_reason.required' => 'Say why the DID is banned (e.g. unpaid invoice).',
            'account_id.required' => 'Choose the customer, or set the status to Free.',
        ]);
        if ($data['status'] === 'free') {
            return [...$data, 'account_id' => null, 'destination' => null, 'status_reason' => null, 'language' => ($data['language'] ?? null) ?: ($did?->language ?? Languages::DEFAULT)];
        }
        $account = Account::findOrFail($data['account_id']);
        $data['destination'] = $this->pbx->destination($account, $request->input('destination'), 'destination.type');
        if ($data['status'] === 'active') {
            $data['status_reason'] = null;
        }

        return $data;
    }

    private function customers()
    {
        return Account::query()->where('is_customer', true)->orderBy('name')->get(['id', 'name', 'code']);
    }

    private function providers()
    {
        return Account::query()->where('is_vendor', true)->orderBy('name')->get(['id', 'name', 'code']);
    }

    private function options(): array
    {
        $customers = $this->customers();

        return [
            'customers' => $customers,
            'providers' => $this->providers(),
            'languages' => Languages::options(),
            'statuses' => PbxDid::STATUSES,
            // Where each customer's DIDs can go: their PBX/Devices and PBX numbers.
            'targets' => $customers->mapWithKeys(fn (Account $a) => [$a->id => [
                'endpoints' => $this->pbx->endpoints($a), 'numbers' => $this->pbx->numbers($a),
            ]]),
        ];
    }
}
