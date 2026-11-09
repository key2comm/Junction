<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Transport;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Gateway;
use App\Models\Trunk;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A carrier's SIP trunks (gateways), managed on the carrier page. A carrier
 * can have several (e.g. primary and failover): lower priority numbers are
 * tried first. Their IP/FQDN is allowed through the firewall on the SIP ports
 * as soon as it's saved (SyncSwitchConfig).
 */
class GatewayController extends Controller
{
    public function store(Request $request, Account $account): RedirectResponse
    {
        abort_unless($account->is_vendor, 404);
        $trunk = $account->ensureCarrierTrunk();
        $gateway = $trunk->gateways()->create($this->validated($request, $trunk));
        Inertia::flash('toast', ['type' => 'success', 'message' => "SIP trunk {$gateway->label} added. {$gateway->host} is allowed through the firewall."]);

        return to_route('accounts.show', $account);
    }

    public function edit(Account $account, Gateway $gateway): Response
    {
        $this->owned($account, $gateway);

        return Inertia::render('accounts/SipTrunkEdit', [
            'account' => $account->only(['id', 'name', 'code']),
            'gateway' => [
                ...$gateway->only(['id', 'label', 'host', 'port', 'username', 'from_user', 'from_domain', 'outbound_proxy',
                    'send_rpid', 'send_pai', 'register', 'enabled', 'priority', 'weight']),
                'transport' => $gateway->transport->value,
                'has_password' => $gateway->getRawOriginal('password') !== null,
            ],
            'transports' => Transport::values(),
        ]);
    }

    public function update(Request $request, Account $account, Gateway $gateway): RedirectResponse
    {
        $this->owned($account, $gateway);
        $gateway->update(self::withPassword($this->validated($request, $gateway->trunk, $gateway), $request));
        Inertia::flash('toast', ['type' => 'success', 'message' => "SIP trunk {$gateway->label} updated."]);

        return to_route('accounts.show', $account);
    }

    public function destroy(Account $account, Gateway $gateway): RedirectResponse
    {
        $this->owned($account, $gateway);
        $gateway->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => "SIP trunk {$gateway->label} removed."]);

        return to_route('accounts.show', $account);
    }

    private function owned(Account $account, Gateway $gateway): void
    {
        abort_unless($gateway->trunk?->account_id === $account->id, 404);
    }

    /** A blank password keeps the saved one; "clear_password" removes it. */
    public static function withPassword(array $data, Request $request): array
    {
        if (($data['password'] ?? '') === '') {
            unset($data['password']);
        }
        if ($request->boolean('clear_password')) {
            $data['password'] = null;
        }

        return $data;
    }

    /** Also used by the REST API, so both validate identically. */
    public function validated(Request $request, Trunk $trunk, ?Gateway $gateway = null): array
    {
        $label = trim((string) ($request->input('label') ?? $gateway?->label ?? $request->input('name') ?? ''));
        // The FreeSWITCH gateway name: given (API), kept, or made from the carrier code and label.
        $name = $request->input('name') ?: $gateway?->name
            ?: Str::slug($trunk->account->code.'-'.($label !== '' ? $label : 'trunk')).'-'.Str::lower(Str::random(4));
        $request->merge([
            'label' => $label !== '' ? $label : null,
            'name' => Str::slug((string) $name),
            'host' => trim((string) $request->input('host')),
            'from_user' => trim((string) $request->input('from_user')) ?: null,
            'from_domain' => trim((string) $request->input('from_domain')) ?: null,
            'outbound_proxy' => trim((string) $request->input('outbound_proxy')) ?: null,
            'register' => $request->boolean('register'),
            'enabled' => $request->boolean('enabled'),
            // The admin form sends "checkboxes" (an unticked box is off); the API may leave them out.
            'send_rpid' => $request->has('send_rpid') || $request->boolean('checkboxes') ? $request->boolean('send_rpid') : ($gateway?->send_rpid ?? true),
            'send_pai' => $request->has('send_pai') || $request->boolean('checkboxes') ? $request->boolean('send_pai') : ($gateway?->send_pai ?? false),
        ]);

        $hostRule = function ($attribute, $value, $fail) {
            $isIp = filter_var($value, FILTER_VALIDATE_IP) !== false;
            $isHost = (bool) preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $value);
            if (! $isIp && ! $isHost) {
                $fail('Enter an IP address or a fully qualified hostname.');
            }
        };
        // "${" would be expanded by FreeSWITCH's config preprocessor.
        $safe = ['not_regex:/[\$<>"]/'];

        $data = $request->validate([
            'label' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9_-]*$/', Rule::unique('gateways', 'name')->ignore($gateway)],
            'host' => ['required', 'string', 'max:255', $hostRule],
            'port' => ['required', 'integer', 'between:1,65535'],
            'transport' => ['required', Rule::enum(Transport::class)],
            'password' => ['nullable', 'string', 'max:128', 'not_regex:/\$\{/'],
            'username' => ['nullable', 'string', 'max:128', ...$safe],
            'from_user' => ['nullable', 'string', 'max:128', 'regex:/^[A-Za-z0-9_.+*#-]+$/'],
            'from_domain' => ['nullable', 'string', 'max:255', $hostRule],
            // host or host:port (an IP or FQDN)
            'outbound_proxy' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9.-]+(:\d{1,5})?$/'],
            'send_rpid' => ['boolean'],
            'send_pai' => ['boolean'],
            'register' => ['boolean'],
            'enabled' => ['boolean'],
            'priority' => ['required', 'integer', 'between:1,100'],
            'weight' => ['required', 'integer', 'between:1,1000'],
        ], [
            'label.required' => 'Give the SIP trunk a name, e.g. Primary or Failover.',
            'from_user.regex' => 'Letters, digits and + * # . _ - only.',
            'outbound_proxy.regex' => 'Enter a host or IP, optionally with :port.',
        ]);

        return $data;
    }
}
