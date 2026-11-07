<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AccountIp;
use App\Rules\IpOrCidr;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
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
        $ip = self::createFor($account, $request->all());
        Inertia::flash('toast', ['type' => 'success', 'message' => "Added {$ip->ip}."]);

        return to_route('accounts.show', $account);
    }

    /** Validate and add an IP (also used by the REST API). */
    public static function createFor(Account $account, array $input): AccountIp
    {
        $input = [
            ...$input,
            'ip' => trim((string) ($input['ip'] ?? '')),
            'tech_prefix' => trim((string) ($input['tech_prefix'] ?? '')),
        ];
        $data = Validator::make($input, [
            'ip' => ['required', 'string', 'max:64', new IpOrCidr],
            'tech_prefix' => ['nullable', 'string', 'max:16', 'regex:/^[0-9#*]*$/'],
            'description' => ['nullable', 'string', 'max:255'],
        ])->validate();
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

        return $account->ips()->create($data);
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
