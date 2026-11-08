<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\PbxDid;
use App\Models\PbxExtension;
use App\Models\PbxIvr;
use App\Services\Pbx;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Minimal PBX of a customer account: extensions (SIP phones), IVRs and DIDs.
 * Calls from extensions to outside numbers use the account's LCR group,
 * rates and funds, exactly like its carrier traffic.
 */
class PbxController extends Controller
{
    public function __construct(private Pbx $pbx) {}

    public function index(): Response
    {
        return Inertia::render('pbx/Index', [
            'accounts' => Account::query()->customers()
                ->withCount(['pbxExtensions', 'pbxIvrs', 'pbxDids'])
                ->orderBy('name')->get(['id', 'name', 'code', 'status'])
                ->map(fn (Account $a) => [
                    ...$a->only(['id', 'name', 'code']),
                    'status' => $a->status->value,
                    'extensions' => $a->pbx_extensions_count, 'ivrs' => $a->pbx_ivrs_count, 'dids' => $a->pbx_dids_count,
                ]),
            'connection' => self::connection(),
        ]);
    }

    public function show(Account $account): Response
    {
        $this->ensureCustomer($account);

        return Inertia::render('pbx/Show', [
            'account' => [...$account->only(['id', 'name', 'code']), 'status' => $account->status->value],
            'extensions' => $account->pbxExtensions()->orderBy('extension')->get()->map(fn (PbxExtension $e) => [
                ...$e->only(['id', 'extension', 'name', 'sip_username', 'caller_id_number', 'outbound_enabled', 'voicemail_enabled', 'ring_seconds', 'enabled']),
                'sip_password' => $e->sip_password,
                'voicemail_pin' => $e->voicemail_pin,
            ]),
            'ivrs' => $account->pbxIvrs()->orderBy('extension')->get()->map(fn (PbxIvr $i) => [
                ...$i->only(['id', 'extension', 'name', 'timeout_seconds']),
                'has_greeting' => $i->greeting !== null,
                'options' => collect($i->options ?? [])->map(fn ($d, $key) => ['key' => (string) $key, 'label' => $this->pbx->describe($account, $d)])->values(),
                'default' => $this->pbx->describe($account, $i->default_destination),
            ]),
            'dids' => $account->pbxDids()->orderBy('number')->get()->map(fn (PbxDid $d) => [
                ...$d->only(['id', 'number', 'description', 'enabled', 'destination']),
                'label' => $this->pbx->describe($account, $d->destination),
            ]),
            'numbers' => $this->pbx->numbers($account),
            'endpoints' => $this->pbx->endpoints($account),
            'connection' => self::connection(),
        ]);
    }

    // --- extensions --------------------------------------------------------

    public function storeExtension(Request $request, Account $account): RedirectResponse
    {
        $this->ensureCustomer($account);
        $data = $this->validatedExtension($request, $account);
        $data['sip_username'] ??= PbxExtension::suggestUsername($account, $data['extension']);
        if (PbxExtension::query()->where('sip_username', $data['sip_username'])->exists()) {
            throw ValidationException::withMessages(['sip_username' => "SIP username {$data['sip_username']} is taken; choose another."]);
        }
        $data['sip_password'] ??= PbxExtension::generatePassword();
        if ($data['voicemail_enabled'] && empty($data['voicemail_pin'])) {
            $data['voicemail_pin'] = (string) random_int(100000, 999999);
        }
        $extension = $account->pbxExtensions()->create($data);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Extension {$extension->extension} created. Its phone signs in as {$extension->sip_username}."]);

        return to_route('accounts.pbx.show', $account);
    }

    public function editExtension(Account $account, PbxExtension $pbxExtension): Response
    {
        return Inertia::render('pbx/ExtensionEdit', [
            'account' => $account->only(['id', 'name', 'code']),
            'extension' => [
                ...$pbxExtension->only(['id', 'extension', 'name', 'sip_username', 'caller_id_number', 'outbound_enabled', 'voicemail_enabled', 'ring_seconds', 'enabled']),
                'voicemail_pin' => $pbxExtension->voicemail_pin,
            ],
        ]);
    }

    public function updateExtension(Request $request, Account $account, PbxExtension $pbxExtension): RedirectResponse
    {
        $data = $this->validatedExtension($request, $account, $pbxExtension);
        // Blank password / username: keep the current one.
        $data = array_filter($data, fn ($v, $k) => ! (in_array($k, ['sip_password', 'sip_username'], true) && $v === null), ARRAY_FILTER_USE_BOTH);
        if (isset($data['sip_username']) && PbxExtension::query()->where('sip_username', $data['sip_username'])->whereKeyNot($pbxExtension->id)->exists()) {
            throw ValidationException::withMessages(['sip_username' => "SIP username {$data['sip_username']} is taken; choose another."]);
        }
        if ($request->boolean('regenerate_password')) {
            $data['sip_password'] = PbxExtension::generatePassword();
        }
        if ($data['voicemail_enabled'] && empty($data['voicemail_pin']) && ! $pbxExtension->voicemail_pin) {
            $data['voicemail_pin'] = (string) random_int(100000, 999999);
        } elseif (empty($data['voicemail_pin'])) {
            unset($data['voicemail_pin']);
        }
        $pbxExtension->update($data);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Extension {$pbxExtension->extension} updated."]);

        return to_route('accounts.pbx.show', $account);
    }

    public function destroyExtension(Account $account, PbxExtension $pbxExtension): RedirectResponse
    {
        $pbxExtension->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => "Extension {$pbxExtension->extension} removed. Destinations that used it now fail until changed."]);

        return to_route('accounts.pbx.show', $account);
    }

    /** @return array<string, mixed> */
    private function validatedExtension(Request $request, Account $account, ?PbxExtension $pbxExtension = null): array
    {
        foreach (['extension', 'sip_username', 'sip_password', 'caller_id_number', 'voicemail_pin', 'name'] as $field) {
            $value = trim((string) $request->input($field));
            $request->merge([$field => $value === '' ? null : $value]);
        }
        $request->merge([
            'sip_username' => $request->input('sip_username') !== null ? strtolower($request->input('sip_username')) : null,
            'caller_id_number' => $request->input('caller_id_number') !== null ? ltrim($request->input('caller_id_number'), '+') : null,
            'outbound_enabled' => $request->boolean('outbound_enabled'),
            'voicemail_enabled' => $request->boolean('voicemail_enabled'),
            'enabled' => $request->boolean('enabled'),
        ]);

        $data = $request->validate([
            'extension' => ['required', 'string', Pbx::NUMBER_RULE],
            'name' => ['nullable', 'string', 'max:64'],
            'sip_username' => ['nullable', 'string', 'regex:/^[a-z0-9][a-z0-9._-]{2,63}$/'],
            'sip_password' => ['nullable', 'string', 'min:12', 'max:64', 'regex:/^[\x21-\x7e]+$/'],
            'caller_id_number' => ['nullable', 'required_if:outbound_enabled,true', 'string', 'regex:/^[0-9]{7,15}$/'],
            'outbound_enabled' => ['boolean'],
            'voicemail_enabled' => ['boolean'],
            'voicemail_pin' => ['nullable', 'string', 'regex:/^[0-9]{4,10}$/'],
            'ring_seconds' => ['required', 'integer', 'min:5', 'max:120'],
            'enabled' => ['boolean'],
        ], [
            'extension.regex' => 'Extensions are 2-6 digits and don\'t start with 0.',
            'sip_username.regex' => 'Use 3-64 lowercase letters, digits, dots, dashes or underscores.',
            'sip_password.regex' => 'Use printable characters without spaces.',
            'caller_id_number.required_if' => 'Outside calls need a caller ID (the number carriers will see).',
            'caller_id_number.regex' => 'Use the full number in international format, digits only (7-15).',
            'voicemail_pin.regex' => 'PINs are 4-10 digits.',
        ]);
        if ($this->pbx->numberTaken($account, $data['extension'], exceptExtension: $pbxExtension)) {
            throw ValidationException::withMessages(['extension' => "{$data['extension']} is already used in this account."]);
        }

        return $data;
    }

    // --- IVRs --------------------------------------------------------------

    public function storeIvr(Request $request, Account $account): RedirectResponse
    {
        $this->ensureCustomer($account);
        $data = $request->validate([
            'extension' => ['required', 'string', Pbx::NUMBER_RULE],
            'name' => ['required', 'string', 'max:64'],
        ], ['extension.regex' => 'IVR numbers are 2-6 digits and don\'t start with 0.']);
        if ($this->pbx->numberTaken($account, $data['extension'])) {
            throw ValidationException::withMessages(['extension' => "{$data['extension']} is already used in this account."]);
        }
        $ivr = $account->pbxIvrs()->create([...$data, 'options' => (object) [], 'timeout_seconds' => 5]);
        Inertia::flash('toast', ['type' => 'success', 'message' => "IVR {$ivr->name} created. Upload a greeting and set its options."]);

        return to_route('accounts.pbx.ivrs.edit', [$account, $ivr]);
    }

    public function editIvr(Account $account, PbxIvr $pbxIvr): Response
    {
        return Inertia::render('pbx/IvrEdit', [
            'account' => $account->only(['id', 'name', 'code']),
            'ivr' => [
                ...$pbxIvr->only(['id', 'extension', 'name', 'timeout_seconds', 'default_destination']),
                'has_greeting' => $pbxIvr->greeting !== null,
                'options' => collect($pbxIvr->options ?? [])->map(fn ($d, $key) => ['key' => (string) $key, ...$d])->sortBy('key')->values(),
            ],
            'numbers' => $this->pbx->numbers($account)->reject(fn ($n) => $n['number'] === $pbxIvr->extension)->values(),
        ]);
    }

    public function updateIvr(Request $request, Account $account, PbxIvr $pbxIvr): RedirectResponse
    {
        $data = $request->validate([
            'extension' => ['required', 'string', Pbx::NUMBER_RULE],
            'name' => ['required', 'string', 'max:64'],
            'timeout_seconds' => ['required', 'integer', 'min:2', 'max:30'],
            'options' => ['nullable', 'array', 'max:12'],
            'greeting' => ['nullable', 'file', 'max:10240'],
            'remove_greeting' => ['nullable', 'boolean'],
        ], ['extension.regex' => 'IVR numbers are 2-6 digits and don\'t start with 0.']);
        if ($this->pbx->numberTaken($account, $data['extension'], exceptIvr: $pbxIvr)) {
            throw ValidationException::withMessages(['extension' => "{$data['extension']} is already used in this account."]);
        }

        $options = [];
        foreach (array_values($data['options'] ?? []) as $i => $row) {
            $key = trim((string) ($row['key'] ?? ''));
            if ($key === '' && empty($row['type'])) {
                continue; // blank row
            }
            if (! preg_match('/^[0-9*#]$/', $key)) {
                throw ValidationException::withMessages(["options.{$i}.key" => 'Use one key: 0-9, * or #.']);
            }
            if (isset($options[$key])) {
                throw ValidationException::withMessages(["options.{$i}.key" => "Key {$key} is used twice."]);
            }
            $options[$key] = $this->pbx->destination($account, $row, "options.{$i}.type");
        }
        ksort($options, SORT_STRING);
        $default = $this->pbx->destination($account, $request->input('default_destination'), 'default_destination.type', required: false);
        foreach ([...array_values($options), $default] as $d) {
            if (($d['target'] ?? null) === $data['extension'] && $d['type'] === 'number') {
                throw ValidationException::withMessages(['options' => 'An IVR can\'t send callers to itself.']);
            }
        }

        $update = [
            'extension' => $data['extension'], 'name' => $data['name'], 'timeout_seconds' => $data['timeout_seconds'],
            // An object, even when empty (array casts would store [] for no options).
            'options' => (object) $options, 'default_destination' => $default,
        ];
        $old = $pbxIvr->greeting;
        if ($file = $request->file('greeting')) {
            if (! self::isWav($file->getRealPath())) {
                throw ValidationException::withMessages(['greeting' => 'Upload a WAV file (PCM, 8-48 kHz).']);
            }
            $name = "ivr-{$pbxIvr->id}-".Str::lower(Str::random(16)).'.wav';
            Storage::disk('local')->putFileAs(PbxIvr::GREETING_PATH, $file, $name);
            $update['greeting'] = $name;
        } elseif ($request->boolean('remove_greeting')) {
            $update['greeting'] = null;
        }
        $pbxIvr->update($update);
        if ($old && $pbxIvr->greeting !== $old) {
            Storage::disk('local')->delete(PbxIvr::GREETING_PATH."/{$old}");
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => "IVR {$pbxIvr->name} saved."]);

        return to_route('accounts.pbx.show', $account);
    }

    public function greeting(Account $account, PbxIvr $pbxIvr): StreamedResponse
    {
        abort_unless($pbxIvr->greeting && Storage::disk('local')->exists(PbxIvr::GREETING_PATH."/{$pbxIvr->greeting}"), 404);

        return Storage::disk('local')->response(PbxIvr::GREETING_PATH."/{$pbxIvr->greeting}", "greeting-{$pbxIvr->extension}.wav", ['Content-Type' => 'audio/wav']);
    }

    public function destroyIvr(Account $account, PbxIvr $pbxIvr): RedirectResponse
    {
        $pbxIvr->delete();
        if ($pbxIvr->greeting) {
            Storage::disk('local')->delete(PbxIvr::GREETING_PATH."/{$pbxIvr->greeting}");
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => "IVR {$pbxIvr->name} removed."]);

        return to_route('accounts.pbx.show', $account);
    }

    /** RIFF/WAVE with a PCM (or IEEE float) fmt chunk at 8-48 kHz: what FreeSWITCH plays without extra modules. */
    public static function isWav(string $path): bool
    {
        $head = (string) @file_get_contents($path, false, null, 0, 64);
        if (strlen($head) < 36 || substr($head, 0, 4) !== 'RIFF' || substr($head, 8, 4) !== 'WAVE') {
            return false;
        }
        $pos = strpos($head, 'fmt ');
        if ($pos === false || strlen($head) < $pos + 16) {
            return false;
        }
        $fmt = unpack('vformat/vchannels/Vrate', substr($head, $pos + 8, 8));

        return in_array($fmt['format'], [1, 3], true) && $fmt['channels'] >= 1 && $fmt['channels'] <= 2
            && $fmt['rate'] >= 8000 && $fmt['rate'] <= 48000;
    }

    // --- DIDs --------------------------------------------------------------

    public function storeDid(Request $request, Account $account): RedirectResponse
    {
        $this->ensureCustomer($account);
        $request->merge(['number' => preg_replace('/\D/', '', (string) $request->input('number'))]);
        $data = $request->validate([
            'number' => ['required', 'string', 'regex:/^[0-9]{7,20}$/', Rule::unique('pbx_dids', 'number')],
            'description' => ['nullable', 'string', 'max:255'],
        ], ['number.regex' => 'Enter the full number as carriers deliver it, in international format (7-20 digits).', 'number.unique' => 'That number is already assigned.']);
        $destination = $this->pbx->destination($account, $request->input('destination'), 'destination.type');
        $did = $account->pbxDids()->create([...$data, 'destination' => $destination, 'enabled' => true]);
        Inertia::flash('toast', ['type' => 'success', 'message' => "DID {$did->number} added."]);

        return to_route('accounts.pbx.show', $account);
    }

    public function updateDid(Request $request, Account $account, PbxDid $pbxDid): RedirectResponse
    {
        $data = $request->validate([
            'description' => ['nullable', 'string', 'max:255'],
            'enabled' => ['sometimes', 'boolean'],
        ]);
        if ($request->has('destination')) {
            $data['destination'] = $this->pbx->destination($account, $request->input('destination'), 'destination.type');
        }
        // Only the fields sent are changed (validate() returns just those).
        $pbxDid->update($data);
        Inertia::flash('toast', ['type' => 'success', 'message' => "DID {$pbxDid->number} updated."]);

        return to_route('accounts.pbx.show', $account);
    }

    public function destroyDid(Account $account, PbxDid $pbxDid): RedirectResponse
    {
        $pbxDid->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => "DID {$pbxDid->number} removed."]);

        return to_route('accounts.pbx.show', $account);
    }

    // --- web phone -----------------------------------------------------------

    public function phone(Request $request, Account $account): Response
    {
        return Inertia::render('pbx/Phone', self::phoneProps($account, $request->integer('extension') ?: null, admin: true));
    }

    /** Props for the web phone page (admin and customer portal). */
    public static function phoneProps(Account $account, ?int $selected, bool $admin): array
    {
        $extensions = $account->pbxExtensions()->where('enabled', true)->orderBy('extension')->get();
        $current = $extensions->firstWhere('id', $selected) ?? $extensions->first();

        return [
            'account' => $account->only(['id', 'name', 'code']),
            'admin' => $admin,
            'extensions' => $extensions->map(fn (PbxExtension $e) => $e->only(['id', 'extension', 'name'])),
            'credentials' => $current ? [
                'id' => $current->id,
                'extension' => $current->extension,
                'name' => $current->name,
                'username' => $current->sip_username,
                'password' => $current->sip_password,
            ] : null,
            'connection' => self::connection(),
        ];
    }

    /** Where phones connect. */
    public static function connection(): array
    {
        $host = config('softswitch.hostname') ?: parse_url((string) config('app.url'), PHP_URL_HOST) ?: request()->getHost();

        return [
            'host' => $host,
            'sip' => $host.':'.config('softswitch.pbx_port'),
            'wss' => "wss://{$host}/ws",
            // Phones register to any name of the switch; FreeSWITCH's SIP domain is its IP.
            'domain' => (string) (config('softswitch.public_ip') ?: $host),
        ];
    }

    private function ensureCustomer(Account $account): void
    {
        abort_unless($account->is_customer, 404, 'The PBX belongs to customer accounts.');
    }
}
