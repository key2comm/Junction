<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AccountIp;
use App\Rules\IpOrCidr;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Customer ingress IPs. These identify the customer on the carrier profile
 * and are added to the firewall allowlist.
 */
class AccountIpController extends Controller
{
    public function store(Request $request, Account $account): RedirectResponse
    {
        $request->merge([
            'ip' => trim((string) $request->input('ip')),
            'tech_prefix' => trim((string) $request->input('tech_prefix')),
        ]);
        $data = $request->validate([
            'ip' => ['required', 'string', 'max:64', new IpOrCidr],
            'tech_prefix' => ['nullable', 'string', 'max:16', 'regex:/^[0-9#*]*$/'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);
        $data['tech_prefix'] ??= '';

        // inet comparison: 198.51.100.7 and 198.51.100.7/32 are the same address.
        $taken = AccountIp::query()
            ->whereRaw('ip = ?::inet', [$data['ip']])
            ->where('tech_prefix', $data['tech_prefix'])
            ->with('account:id,code')
            ->first();
        if ($taken) {
            throw ValidationException::withMessages([
                'ip' => "{$data['ip']} is already assigned to {$taken->account->code}".($data['tech_prefix'] !== '' ? " with tech prefix {$data['tech_prefix']}" : '').'.',
            ]);
        }

        $account->ips()->create($data);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Added {$data['ip']}."]);

        return to_route('accounts.show', $account);
    }

    public function update(Request $request, Account $account, AccountIp $ip): RedirectResponse
    {
        $ip->update($request->validate(['enabled' => ['required', 'boolean']]));

        return to_route('accounts.show', $account);
    }

    public function destroy(Account $account, AccountIp $ip): RedirectResponse
    {
        $ip->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => "Removed {$ip->ip}."]);

        return to_route('accounts.show', $account);
    }
}
