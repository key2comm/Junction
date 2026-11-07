<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Transport;
use App\Http\Controllers\Controller;
use App\Models\Gateway;
use App\Models\Trunk;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Vendor SIP endpoints, managed from the trunk page.
 */
class GatewayController extends Controller
{
    public function store(Request $request, Trunk $trunk): RedirectResponse
    {
        $gateway = $trunk->gateways()->create($this->validated($request, $trunk));
        Inertia::flash('toast', ['type' => 'success', 'message' => "Gateway {$gateway->name} added."]);

        return to_route('trunks.show', $trunk);
    }

    public function edit(Trunk $trunk, Gateway $gateway): Response
    {
        return Inertia::render('trunks/GatewayEdit', [
            'trunk' => $trunk->only(['id', 'name']),
            'gateway' => [
                ...$gateway->only(['id', 'name', 'host', 'port', 'username', 'register', 'enabled', 'priority', 'weight']),
                'transport' => $gateway->transport->value,
                'has_password' => $gateway->getRawOriginal('password') !== null,
            ],
            'transports' => Transport::values(),
        ]);
    }

    public function update(Request $request, Trunk $trunk, Gateway $gateway): RedirectResponse
    {
        $data = $this->validated($request, $trunk, $gateway);
        // A blank password field keeps the current password; tick "clear" to remove it.
        if (($data['password'] ?? '') === '') {
            unset($data['password']);
        }
        if ($request->boolean('clear_password')) {
            $data['password'] = null;
        }
        $gateway->update($data);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Gateway updated.']);

        return to_route('trunks.show', $trunk);
    }

    public function destroy(Trunk $trunk, Gateway $gateway): RedirectResponse
    {
        $gateway->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => "Gateway {$gateway->name} removed."]);

        return to_route('trunks.show', $trunk);
    }

    /** Also used by the REST API, so both validate identically. */
    public function validated(Request $request, Trunk $trunk, ?Gateway $gateway = null): array
    {
        $request->merge([
            'name' => Str::slug((string) ($request->input('name') ?: $trunk->account->code.'-'.$trunk->name.'-'.Str::lower(Str::random(4)))),
            'host' => trim((string) $request->input('host')),
            'register' => $request->boolean('register'),
            'enabled' => $request->boolean('enabled'),
        ]);

        return $request->validate([
            'name' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9_-]*$/', Rule::unique('gateways', 'name')->ignore($gateway)],
            // Hostname or IPv4/IPv6 address
            'host' => ['required', 'string', 'max:255', function ($attribute, $value, $fail) {
                $isIp = filter_var($value, FILTER_VALIDATE_IP) !== false;
                $isHost = (bool) preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $value);
                if (! $isIp && ! $isHost) {
                    $fail('Enter an IP address or a fully qualified hostname.');
                }
            }],
            'port' => ['required', 'integer', 'between:1,65535'],
            'transport' => ['required', Rule::enum(Transport::class)],
            // "${" would be expanded by FreeSWITCH's config preprocessor.
            'password' => ['nullable', 'string', 'max:128', 'not_regex:/\$\{/'],
            'username' => ['nullable', 'string', 'max:128', 'not_regex:/\$\{/'],
            'register' => ['boolean'],
            'enabled' => ['boolean'],
            'priority' => ['required', 'integer', 'between:1,100'],
            'weight' => ['required', 'integer', 'between:1,1000'],
        ]);
    }
}
