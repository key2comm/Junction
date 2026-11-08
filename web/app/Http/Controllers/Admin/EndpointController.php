<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Endpoint;
use App\Models\EndpointRule;
use App\Models\PbxDid;
use App\Services\Pbx;
use App\Support\IpNet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Routing -> PBX/Devices. A PBX is shared (no customer): DIDs of any customer
 * can go to it. A device belongs to a customer and is one or the other:
 * "Sending calls" (to its IP/FQDN, down a SIP trunk the outside PBX registers
 * with a username and password, or a call flow) or "Receiving calls" (it sends
 * calls to us, recognised by From domain, IP/FQDN or caller number rules).
 */
class EndpointController extends Controller
{
    public function __construct(private Pbx $pbx) {}

    public function index(Request $request): Response
    {
        $account = $request->integer('account') ?: null;
        $kind = in_array($request->input('kind'), ['pbx', 'device'], true) ? $request->input('kind') : null;
        $didCounts = PbxDid::query()->where('destination->type', 'endpoint')
            ->selectRaw("destination->>'target' AS id, count(*) AS n")->groupByRaw("destination->>'target'")->pluck('n', 'id');

        $endpoints = Endpoint::query()->with('account:id,name,code')->withCount('rules')
            ->when($account, fn ($q) => $q->where('account_id', $account))
            ->when($kind, fn ($q) => $q->where('kind', $kind))
            ->orderBy('kind')->orderBy('name')->get()
            ->map(fn (Endpoint $e) => [
                ...$e->only(['id', 'kind', 'mode', 'name', 'enabled', 'codecs', 'max_inbound', 'max_outbound', 'max_total', 'sip_username', 'rules_count']),
                'account' => $e->account?->only(['id', 'name', 'code']),
                'summary' => $e->summary(),
                'dids' => (int) ($didCounts[(string) $e->id] ?? 0),
            ]);

        return Inertia::render('endpoints/Index', [
            'endpoints' => $endpoints,
            'customers' => $this->customers(),
            'filters' => ['account' => $account, 'kind' => $kind],
            'codecs' => Endpoint::CODECS,
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('endpoints/Form', [
            'endpoint' => null,
            'defaults' => [
                'account_id' => $request->integer('account') ?: null,
                'kind' => $request->input('kind') === 'device' || $request->integer('account') ? 'device' : 'pbx',
                'sip_password' => Endpoint::generatePassword(),
            ],
            ...$this->options(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        [$data, $rules] = $this->validated($request);
        $endpoint = DB::transaction(function () use ($data, $rules) {
            $endpoint = Endpoint::create($data);
            $this->saveRules($endpoint, $rules);

            return $endpoint;
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => Endpoint::KINDS[$endpoint->kind]." {$endpoint->name} created."]);

        return to_route('endpoints.index');
    }

    public function edit(Endpoint $endpoint): Response
    {
        return Inertia::render('endpoints/Form', [
            'endpoint' => [
                ...$endpoint->only(['id', 'account_id', 'kind', 'mode', 'name', 'enabled', 'hosts', 'transport', 'port', 'delivery', 'flow',
                    'forward_number', 'ring_seconds', 'pbx_destination', 'codecs', 'caller_id_number', 'forward_caller_id',
                    'max_inbound', 'max_outbound', 'max_total', 'notes', 'sip_username']),
                'sip_password' => $endpoint->sip_password,
                'rules' => $endpoint->rules->map(fn (EndpointRule $r) => $r->only(['type', 'value']))->values(),
                'dids' => $this->didCount($endpoint),
            ],
            'defaults' => ['sip_password' => Endpoint::generatePassword()],
            ...$this->options(),
        ]);
    }

    public function update(Request $request, Endpoint $endpoint): RedirectResponse
    {
        [$data, $rules] = $this->validated($request, $endpoint);
        DB::transaction(function () use ($endpoint, $data, $rules) {
            $endpoint->update($data);
            $this->saveRules($endpoint, $rules);
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => Endpoint::KINDS[$endpoint->kind]." {$endpoint->name} saved."]);

        return to_route('endpoints.index');
    }

    public function destroy(Request $request, Endpoint $endpoint): RedirectResponse
    {
        $request->validate(['confirm' => ['required', 'string']]);
        if (trim($request->string('confirm')) !== trim($endpoint->name)) {
            throw ValidationException::withMessages(['confirm' => 'Type the name exactly as shown to confirm.']);
        }
        $dids = PbxDid::query()->where('destination->type', 'endpoint')->where('destination->target', (string) $endpoint->id)->orderBy('number')->pluck('number');
        if ($dids->isNotEmpty()) {
            throw ValidationException::withMessages(['confirm' => $dids->count().' DID(s) still send calls here ('.$dids->take(5)->implode(', ').($dids->count() > 5 ? '…' : '').'). Point them somewhere else first.']);
        }
        $endpoint->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$endpoint->name} deleted."]);

        return to_route('endpoints.index');
    }

    /** @return array{0: array<string, mixed>, 1: list<array{type: string, value: string}>} */
    private function validated(Request $request, ?Endpoint $endpoint = null): array
    {
        $kind = $request->input('kind') === 'device' ? 'device' : 'pbx';
        $isDevice = $kind === 'device';
        // A device either has calls sent to it, or sends calls to us (recognised by its rules): never both.
        $receiving = $isDevice && $request->input('mode') === 'receiving';
        // Sending calls to a device: to its IP/FQDN, or down its SIP trunk (where the PBX registers with the login).
        $login = $isDevice && ! $receiving && $request->input('target') === 'trunk';
        $request->merge([
            'kind' => $request->input('kind'),
            'hosts' => array_values(array_filter(array_map(fn ($h) => strtolower(trim((string) $h)), (array) $request->input('hosts', [])))),
            'forward_number' => preg_replace('/\D/', '', (string) $request->input('forward_number')) ?: null,
            'caller_id_number' => preg_replace('/[^\d+]/', '', (string) $request->input('caller_id_number')) ?: null,
            'codecs' => array_values(array_intersect(array_keys(Endpoint::CODECS), (array) $request->input('codecs', []))),
            'sip_username' => $login ? trim((string) $request->input('sip_username')) : null,
            'rules' => $receiving ? array_values(array_filter(array_map(fn ($r) => [
                'type' => (string) ($r['type'] ?? ''),
                'value' => ($r['type'] ?? '') === 'number' ? trim((string) ($r['value'] ?? '')) : strtolower(trim(ltrim((string) ($r['value'] ?? ''), '@'))),
            ], (array) $request->input('rules', [])), fn ($r) => $r['value'] !== '')) : [],
        ]);
        $delivery = $isDevice && ! $receiving ? $request->input('delivery') : 'sip';
        $flow = $delivery === 'flow' ? $request->input('flow') : null;
        $ringsHosts = $delivery === 'sip' || in_array($flow, Endpoint::RINGING_FLOWS, true);
        $needsHosts = $ringsHosts && ! $login && ! $receiving;
        $forwards = $flow !== null && $flow !== 'pbx';

        $data = $request->validate([
            'account_id' => [$isDevice ? 'required' : 'nullable', 'integer', Rule::exists('accounts', 'id')->where('is_customer', true)],
            'kind' => ['required', Rule::in(array_keys(Endpoint::KINDS))],
            'mode' => [$isDevice ? 'required' : 'nullable', Rule::in(array_keys(Endpoint::MODES))],
            'name' => ['required', 'string', 'max:100', $isDevice
                ? Rule::unique('endpoints', 'name')->where('account_id', $request->integer('account_id'))->ignore($endpoint)
                : Rule::unique('endpoints', 'name')->whereNull('account_id')->ignore($endpoint)],
            'enabled' => ['boolean'],
            'delivery' => [$isDevice && ! $receiving ? 'required' : 'nullable', 'in:sip,flow'],
            'flow' => [$delivery === 'flow' ? 'required' : 'nullable', Rule::in(array_keys(Endpoint::FLOWS))],
            'hosts' => [$needsHosts ? 'required' : 'nullable', 'array', 'max:'.Endpoint::MAX_HOSTS[$kind]],
            'hosts.*' => ['string', 'max:253'],
            'transport' => ['nullable', Rule::in(array_keys(Endpoint::TRANSPORTS))],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'ring_seconds' => ['nullable', 'integer', 'between:5,120'],
            'forward_number' => [$forwards ? 'required' : 'nullable', 'regex:/^[1-9][0-9]{9,14}$/'],
            'codecs' => ['required', 'array', 'min:1'],
            'caller_id_number' => ['nullable', 'regex:/^\+?[0-9]{3,15}$/'],
            'forward_caller_id' => ['nullable', 'in:original,endpoint'],
            'max_inbound' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'max_outbound' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'max_total' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'rules' => [$receiving ? 'required' : 'nullable', 'array', 'max:50'],
            'rules.*.type' => ['required', Rule::in(array_keys(Endpoint::RULE_TYPES))],
            'rules.*.value' => ['required', 'string', 'max:253'],
            'sip_username' => [$login ? 'required' : 'nullable', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]{2,63}$/'],
            'sip_password' => [$login ? 'required' : 'nullable', 'string', 'min:12', 'max:64', 'regex:/^[A-Za-z0-9!#%*+,.\/:=?@^_~-]+$/'],
        ], [
            'hosts.required' => $isDevice ? 'Enter where to send the calls (an IP address or hostname).' : 'Enter the PBX\'s IP address or hostname.',
            'hosts.max' => 'Enter one IP/FQDN.',
            'rules.required' => 'Add at least one rule to recognise the calls this device sends.',
            'forward_number.required' => 'Enter the number to forward to.',
            'forward_number.regex' => 'Enter the full number with country code, e.g. 14165551234.',
            'caller_id_number.regex' => 'A caller ID is 3 to 15 digits.',
            'codecs.required' => 'Choose at least one codec.',
            'sip_username.regex' => 'Use 3-64 letters, digits, dots, dashes or underscores.',
            'sip_password.regex' => 'Use letters, digits and common symbols (no spaces, quotes or < > &).',
        ]);

        foreach ($data['hosts'] ?? [] as $i => $host) {
            $this->checkHost($host, "hosts.{$i}");
        }
        $rules = [];
        foreach ($data['rules'] ?? [] as $i => $r) {
            $field = "rules.{$i}.value";
            match ($r['type']) {
                'domain' => IpNet::isHostname($r['value']) || throw ValidationException::withMessages([$field => "{$r['value']} isn't a domain (e.g. tenant.example.com)."]),
                'ip' => $this->checkHost($r['value'], $field),
                'number' => preg_match('/^\+?[0-9]{3,20}$/', $r['value']) || throw ValidationException::withMessages([$field => 'Enter the caller number exactly as the PBX sends it, e.g. +14165551234.']),
            };
            $key = $r['type'].'|'.$r['value'];
            if (isset($rules[$key])) {
                continue;
            }
            $owner = EndpointRule::query()->where('type', $r['type'])->where('value', $r['value'])->when($endpoint, fn ($q) => $q->where('endpoint_id', '!=', $endpoint->id))->with('endpoint:id,name')->first();
            if ($owner) {
                throw ValidationException::withMessages([$field => "{$r['value']} already identifies {$owner->endpoint->name}."]);
            }
            $rules[$key] = $r;
        }
        if ($receiving && $endpoint && ($dids = $this->didCount($endpoint))) {
            throw ValidationException::withMessages(['mode' => "{$dids} DID(s) send calls to this device. Point them somewhere else before it only receives calls."]);
        }
        if ($login && Endpoint::usernameTaken($data['sip_username'], $endpoint)) {
            throw ValidationException::withMessages(['sip_username' => "SIP username {$data['sip_username']} is taken; choose another."]);
        }
        if ($forwards && $data['caller_id_number'] === null && ($data['forward_caller_id'] ?? 'original') === 'endpoint') {
            throw ValidationException::withMessages(['caller_id_number' => 'Enter the caller ID that forwarded calls should show.']);
        }
        if (isset($data['max_total']) && (($data['max_inbound'] ?? 0) > $data['max_total'] || ($data['max_outbound'] ?? 0) > $data['max_total'])) {
            throw ValidationException::withMessages(['max_total' => 'The total limit can\'t be lower than the inbound or outbound limit.']);
        }

        $pbxDestination = null;
        if ($flow === 'pbx') {
            $pbxDestination = $this->pbx->destination(Account::findOrFail($data['account_id']), $request->input('pbx_destination'), 'pbx_destination.type', endpoints: false);
        }

        return [[
            'account_id' => $isDevice ? $data['account_id'] : null,
            'kind' => $kind,
            'mode' => $receiving ? 'receiving' : 'sending',
            'name' => $data['name'],
            'enabled' => $request->boolean('enabled'),
            'delivery' => $delivery,
            'flow' => $flow,
            'hosts' => $ringsHosts && ! $login ? ($data['hosts'] ?? []) : [],
            'transport' => $data['transport'] ?? 'udp',
            'port' => $data['port'] ?? 5060,
            'ring_seconds' => $data['ring_seconds'] ?? 25,
            'forward_number' => $forwards ? $data['forward_number'] : null,
            'forward_caller_id' => $data['forward_caller_id'] ?? 'original',
            'pbx_destination' => $pbxDestination,
            'codecs' => $data['codecs'],
            // A shared PBX only receives calls: no caller ID or outbound limits of its own.
            'caller_id_number' => $isDevice ? $data['caller_id_number'] : null,
            'max_inbound' => $receiving ? null : ($data['max_inbound'] ?? null),
            // Outbound limits: for calls a device sends us (receiving, or over its SIP trunk).
            'max_outbound' => $receiving || $login ? ($data['max_outbound'] ?? null) : null,
            'max_total' => $login ? ($data['max_total'] ?? null) : null,
            'notes' => $data['notes'] ?? null,
            'sip_username' => $login ? $data['sip_username'] : null,
            'sip_password' => $login ? $data['sip_password'] : null,
        ], array_values($rules)];
    }

    private function didCount(Endpoint $endpoint): int
    {
        return PbxDid::query()->where('destination->type', 'endpoint')->where('destination->target', (string) $endpoint->id)->count();
    }

    private function checkHost(string $host, string $field): void
    {
        $ip = IpNet::normalize($host);
        if ($ip === null && ! IpNet::isHostname($host)) {
            throw ValidationException::withMessages([$field => "{$host} isn't an IP address or hostname."]);
        }
        if ($ip !== null && str_contains($ip, '/')) {
            throw ValidationException::withMessages([$field => 'Enter a single address, not a network.']);
        }
    }

    /** @param  list<array{type: string, value: string}>  $rules */
    private function saveRules(Endpoint $endpoint, array $rules): void
    {
        $keep = [];
        foreach ($rules as $r) {
            $keep[] = $endpoint->rules()->firstOrCreate($r)->id;
        }
        $endpoint->rules()->whereNotIn('id', $keep)->get()->each->delete();
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
            'modes' => Endpoint::MODES,
            'transports' => Endpoint::TRANSPORTS,
            'codecs' => Endpoint::CODECS,
            'flows' => Endpoint::FLOWS,
            'ruleTypes' => Endpoint::RULE_TYPES,
            'connection' => PbxController::connection(),
            // Each customer's own PBX numbers, for the "PBX on this softswitch" flow.
            'pbxNumbers' => $customers->mapWithKeys(fn (Account $a) => [$a->id => $this->pbx->numbers($a)])->filter(fn ($n) => $n->isNotEmpty()),
        ];
    }
}
