<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Endpoint;
use App\Services\Pbx;
use App\Support\IpNet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Routing -> PBX/Devices: where customers' calls (DIDs, forwards) are delivered. */
class EndpointController extends Controller
{
    public function __construct(private Pbx $pbx) {}

    public function index(Request $request): Response
    {
        $account = $request->integer('account') ?: null;
        $endpoints = Endpoint::query()->with('account:id,name,code')
            ->when($account, fn ($q) => $q->where('account_id', $account))
            ->orderBy('name')->get()
            ->map(fn (Endpoint $e) => [
                ...$e->only(['id', 'kind', 'name', 'enabled', 'codecs', 'max_inbound', 'max_outbound', 'max_total', 'outbound_enabled']),
                'account' => $e->account->only(['id', 'name', 'code']),
                'summary' => $e->summary(),
            ]);

        return Inertia::render('endpoints/Index', [
            'endpoints' => $endpoints,
            'customers' => $this->customers(),
            'filters' => ['account' => $account],
            'codecs' => Endpoint::CODECS,
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('endpoints/Form', [
            'endpoint' => null,
            'defaults' => ['account_id' => $request->integer('account') ?: null],
            ...$this->options(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $endpoint = Endpoint::create($this->validated($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => Endpoint::KINDS[$endpoint->kind]." {$endpoint->name} created."]);

        return to_route('endpoints.index');
    }

    public function edit(Endpoint $endpoint): Response
    {
        return Inertia::render('endpoints/Form', [
            'endpoint' => $endpoint->only(['id', 'account_id', 'kind', 'name', 'enabled', 'hosts', 'transport', 'port', 'delivery', 'flow',
                'forward_number', 'ring_seconds', 'pbx_destination', 'codecs', 'caller_id_number', 'forward_caller_id', 'outbound_enabled',
                'max_inbound', 'max_outbound', 'max_total', 'notes']),
            'defaults' => [],
            ...$this->options(),
        ]);
    }

    public function update(Request $request, Endpoint $endpoint): RedirectResponse
    {
        $endpoint->update($this->validated($request, $endpoint));
        Inertia::flash('toast', ['type' => 'success', 'message' => Endpoint::KINDS[$endpoint->kind]." {$endpoint->name} saved."]);

        return to_route('endpoints.index');
    }

    public function destroy(Request $request, Endpoint $endpoint): RedirectResponse
    {
        $request->validate(['confirm' => ['required', 'string']]);
        if (trim($request->string('confirm')) !== trim($endpoint->name)) {
            throw ValidationException::withMessages(['confirm' => 'Type the name exactly as shown to confirm.']);
        }
        $dids = $endpoint->account->pbxDids()->where('destination->type', 'endpoint')->where('destination->target', (string) $endpoint->id)->pluck('number');
        if ($dids->isNotEmpty()) {
            throw ValidationException::withMessages(['confirm' => 'DIDs still send calls here: '.$dids->implode(', ').'. Point them somewhere else first.']);
        }
        $endpoint->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$endpoint->name} deleted."]);

        return to_route('endpoints.index');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Endpoint $endpoint = null): array
    {
        $request->merge([
            'hosts' => array_values(array_filter(array_map(fn ($h) => strtolower(trim((string) $h)), (array) $request->input('hosts', [])))),
            'forward_number' => preg_replace('/\D/', '', (string) $request->input('forward_number')) ?: null,
            'caller_id_number' => preg_replace('/[^\d+]/', '', (string) $request->input('caller_id_number')) ?: null,
            'codecs' => array_values(array_intersect(array_keys(Endpoint::CODECS), (array) $request->input('codecs', []))),
        ]);
        $kind = $request->input('kind');
        $delivery = $kind === 'device' ? $request->input('delivery') : 'sip';
        $flow = $delivery === 'flow' ? $request->input('flow') : null;
        $ringsHosts = $delivery === 'sip' || in_array($flow, Endpoint::RINGING_FLOWS, true);
        $forwards = $flow !== null && $flow !== 'pbx';

        $data = $request->validate([
            'account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('is_customer', true)],
            'kind' => ['required', Rule::in(array_keys(Endpoint::KINDS))],
            'name' => ['required', 'string', 'max:100', Rule::unique('endpoints', 'name')->where('account_id', $request->integer('account_id'))->ignore($endpoint)],
            'enabled' => ['boolean'],
            'delivery' => [$kind === 'device' ? 'required' : 'nullable', 'in:sip,flow'],
            'flow' => [$delivery === 'flow' ? 'required' : 'nullable', Rule::in(array_keys(Endpoint::FLOWS))],
            'hosts' => [$ringsHosts ? 'required' : 'nullable', 'array', 'max:'.(Endpoint::MAX_HOSTS[$kind] ?? 1)],
            'hosts.*' => ['string', 'max:253'],
            'transport' => [$ringsHosts ? 'required' : 'nullable', Rule::in(array_keys(Endpoint::TRANSPORTS))],
            'port' => [$ringsHosts ? 'required' : 'nullable', 'integer', 'between:1,65535'],
            'ring_seconds' => ['nullable', 'integer', 'between:5,120'],
            'forward_number' => [$forwards ? 'required' : 'nullable', 'regex:/^[1-9][0-9]{9,14}$/'],
            'codecs' => ['required', 'array', 'min:1'],
            'caller_id_number' => ['nullable', 'regex:/^\+?[0-9]{3,15}$/'],
            'forward_caller_id' => ['required', 'in:original,endpoint'],
            'outbound_enabled' => ['boolean'],
            'max_inbound' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'max_outbound' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'max_total' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'hosts.required' => 'Enter where to send the calls (an IP address or hostname).',
            'hosts.max' => $kind === 'device' ? 'A device can have up to 3 addresses.' : 'A PBX has one address.',
            'forward_number.required' => 'Enter the number to forward to.',
            'forward_number.regex' => 'Enter the full number with country code, e.g. 14165551234.',
            'caller_id_number.regex' => 'A caller ID is 3 to 15 digits.',
            'codecs.required' => 'Choose at least one codec.',
        ]);

        foreach ($data['hosts'] ?? [] as $i => $host) {
            if (IpNet::normalize($host) === null && ! IpNet::isHostname($host)) {
                throw ValidationException::withMessages(["hosts.{$i}" => "{$host} isn't an IP address or hostname."]);
            }
            if (str_contains((string) IpNet::normalize($host), '/')) {
                throw ValidationException::withMessages(["hosts.{$i}" => 'Enter a single address, not a network.']);
            }
        }
        if ($data['caller_id_number'] === null && $data['forward_caller_id'] === 'endpoint' && $forwards) {
            throw ValidationException::withMessages(['caller_id_number' => 'Enter the caller ID that forwarded calls should show.']);
        }
        if (isset($data['max_total']) && (($data['max_inbound'] ?? 0) > $data['max_total'] || ($data['max_outbound'] ?? 0) > $data['max_total'])) {
            throw ValidationException::withMessages(['max_total' => 'The total limit can\'t be lower than the inbound or outbound limit.']);
        }

        $pbxDestination = null;
        if ($flow === 'pbx') {
            $account = Account::findOrFail($data['account_id']);
            $pbxDestination = $this->pbx->destination($account, $request->input('pbx_destination'), 'pbx_destination.type', endpoints: false);
        }

        return [
            ...$data,
            'enabled' => $request->boolean('enabled'),
            'outbound_enabled' => $request->boolean('outbound_enabled'),
            'delivery' => $delivery,
            'flow' => $flow,
            'hosts' => $ringsHosts ? $data['hosts'] : [],
            'transport' => $data['transport'] ?? 'udp',
            'port' => $data['port'] ?? 5060,
            'ring_seconds' => $data['ring_seconds'] ?? 25,
            'forward_number' => $forwards ? $data['forward_number'] : null,
            'pbx_destination' => $pbxDestination,
        ];
    }

    private function customers()
    {
        return Account::query()->where('is_customer', true)->orderBy('name')->get(['id', 'name', 'code']);
    }

    private function options(): array
    {
        $customers = $this->customers();

        return [
            'customers' => $customers,
            'kinds' => Endpoint::KINDS,
            'transports' => Endpoint::TRANSPORTS,
            'codecs' => Endpoint::CODECS,
            'flows' => Endpoint::FLOWS,
            // Each customer's own PBX numbers, for the "PBX on this softswitch" flow.
            'pbxNumbers' => $customers->mapWithKeys(fn (Account $a) => [$a->id => $this->pbx->numbers($a)])->filter(fn ($n) => $n->isNotEmpty()),
        ];
    }
}
