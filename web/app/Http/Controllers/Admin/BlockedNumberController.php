<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\BlockedNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Customers → a customer → Blocked numbers: exact numbers the customer may not
 * call, or receive calls from on its DIDs. The call path reads them live.
 */
class BlockedNumberController extends Controller
{
    public function store(Request $request, Account $account): RedirectResponse
    {
        abort_unless($account->is_customer, 404);
        $request->merge(['number' => BlockedNumber::normalize((string) $request->input('number'))]);
        $data = $request->validate([
            'number' => ['required', 'regex:/^[0-9]{3,20}$/', Rule::unique('account_blocked_numbers', 'number')->where('account_id', $account->id)],
            'direction' => ['required', Rule::in(array_keys(BlockedNumber::DIRECTIONS))],
            'reason' => ['nullable', 'string', 'max:255'],
        ], [
            'number.required' => 'Enter the number to block.',
            'number.regex' => 'Enter the number in international format (3-20 digits), e.g. 14165551234.',
            'number.unique' => 'That number is already blocked for this customer.',
        ]);
        $blocked = $account->blockedNumbers()->create($data);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Blocked {$blocked->number}."]);

        return to_route('accounts.show', $account);
    }

    public function update(Request $request, Account $account, BlockedNumber $blockedNumber): RedirectResponse
    {
        $blockedNumber->update($request->validate([
            'direction' => ['required', Rule::in(array_keys(BlockedNumber::DIRECTIONS))],
            'reason' => ['nullable', 'string', 'max:255'],
        ]));

        return to_route('accounts.show', $account);
    }

    public function destroy(Account $account, BlockedNumber $blockedNumber): RedirectResponse
    {
        $blockedNumber->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => "Unblocked {$blockedNumber->number}."]);

        return to_route('accounts.show', $account);
    }
}
