<?php

namespace App\Http\Controllers\Admin;

use App\Enums\LedgerEntryType;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Services\Ledger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use InvalidArgumentException;

/**
 * Manual money movements: payments received and adjustments (credits or
 * debits). Usage charges are posted by the billing jobs, not from the UI.
 */
class LedgerEntryController extends Controller
{
    public function store(Request $request, Account $account, Ledger $ledger): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:payment,adjustment'],
            'amount' => ['required', 'numeric', 'decimal:0,6', 'not_in:0', 'min:-10000000', 'max:10000000'],
            'description' => ['required', 'string', 'max:255'],
        ]);

        try {
            $entry = $ledger->post(
                $account,
                LedgerEntryType::from($data['type']),
                (string) $data['amount'],
                $data['description'],
                user: $request->user(),
            );
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => ucfirst($data['type'])." of {$entry->amount} {$entry->currency} recorded."]);

        return to_route('accounts.show', $account);
    }
}
