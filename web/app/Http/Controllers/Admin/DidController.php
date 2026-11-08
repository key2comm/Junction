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
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:64'],
            'account' => ['nullable', 'integer'],
            'provider' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(array_keys(PbxDid::STATUSES))],
            'endpoint' => ['nullable', 'integer'],
        ]);
        $digits = preg_replace('/\D/', '', (string) ($filters['q'] ?? ''));

        $dids = PbxDid::query()->with(['account:id,name,code', 'provider:id,name,code'])
            ->when($filters['q'] ?? null, fn ($q) => $q->where(fn ($w) => $w
                ->when($digits !== '', fn ($x) => $x->where('number', 'like', "%{$digits}%"))
                ->orWhere('description', 'ilike', '%'.addcslashes($filters['q'], '%_\\').'%')))
            ->when($filters['account'] ?? null, fn ($q, $id) => $q->where('account_id', $id))
            ->when($filters['provider'] ?? null, fn ($q, $id) => $q->where('provider_account_id', $id))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['endpoint'] ?? null, fn ($q, $id) => $q->where('destination->type', 'endpoint')->where('destination->target', (string) $id))
            ->orderBy('number')
            ->paginate(50)->withQueryString();

        $dids->through(function (PbxDid $d) {
            return [
                ...$d->only(['id', 'number', 'language', 'status', 'status_reason', 'description']),
                'account' => $d->account->only(['id', 'name', 'code']),
                'provider' => $d->provider?->only(['id', 'name', 'code']),
                'destination' => $this->pbx->describe($d->account, $d->destination),
            ];
        });

        return Inertia::render('dids/Index', [
            'dids' => $dids,
            'filters' => ['q' => $filters['q'] ?? '', 'account' => $filters['account'] ?? null, 'provider' => $filters['provider'] ?? null, 'status' => $filters['status'] ?? '',
                'endpoint' => ($id = $filters['endpoint'] ?? null) ? Endpoint::query()->whereKey($id)->first(['id', 'kind', 'name'])?->only(['id', 'kind', 'name']) : null],
            'customers' => $this->customers(),
            'providers' => $this->providers(),
            'statuses' => PbxDid::STATUSES,
            'counts' => PbxDid::query()->selectRaw('status, count(*) AS n')->groupBy('status')->pluck('n', 'status'),
        ]);
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
            'account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('is_customer', true)],
            'provider_account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('is_vendor', true)],
            'language' => ['required', Rule::in(array_keys(Languages::ALL))],
            'status' => ['required', Rule::in(array_keys(PbxDid::STATUSES))],
            'status_reason' => [$request->input('status') === 'banned' ? 'required' : 'nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
        ], [
            'number.regex' => 'Enter the full number as the provider delivers it, in international format (7-20 digits), e.g. 14165551234.',
            'number.unique' => 'That number is already assigned.',
            'provider_account_id.required' => 'Choose the provider (the carrier the number comes from).',
            'provider_account_id.exists' => 'Choose one of your carriers.',
            'status_reason.required' => 'Say why the DID is banned (e.g. unpaid invoice).',
        ]);
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
