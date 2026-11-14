<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AccountCharge;
use App\Models\BillingItem;
use App\Models\BillingProfile;
use App\Services\BillingCharges;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** A customer's billing plan: their profile and the items on their bill. */
class AccountChargeController extends Controller
{
    public function __construct(private BillingCharges $charges) {}

    public function profile(Request $request, Account $account): RedirectResponse
    {
        abort_unless($account->is_customer, 404);
        $data = $request->validate(['billing_profile_id' => ['nullable', 'integer', 'exists:billing_profiles,id']]);
        $profile = ($data['billing_profile_id'] ?? null) ? BillingProfile::findOrFail($data['billing_profile_id']) : null;
        $this->charges->assignProfile($account, $profile, $request->user());
        Inertia::flash('toast', ['type' => 'success', 'message' => $profile ? "{$account->code} is on {$profile->name}." : "{$account->code} has no billing profile now."]);

        return back();
    }

    public function store(Request $request, Account $account): RedirectResponse
    {
        abort_unless($account->is_customer, 404);
        $data = $this->validated($request, true);
        $this->charges->add($account, BillingItem::findOrFail($data['billing_item_id']), (string) $data['quantity'], $data['price'], false, $request->user());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Item added to the bill.']);

        return back();
    }

    public function update(Request $request, Account $account, AccountCharge $charge): RedirectResponse
    {
        $data = $this->validated($request, false);
        $this->charges->update($charge, (string) $data['quantity'], $data['price'], $request->user());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Item updated.']);

        return back();
    }

    public function destroy(Request $request, Account $account, AccountCharge $charge): RedirectResponse
    {
        $this->charges->remove($charge, $request->user());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Item removed from the bill.']);

        return back();
    }

    /** @return array{billing_item_id?: int, quantity: string, price: string|null} */
    private function validated(Request $request, bool $new): array
    {
        $data = $request->validate([
            ...($new ? ['billing_item_id' => ['required', 'integer', 'exists:billing_items,id']] : []),
            'quantity' => ['required', 'numeric', 'min:0.01', 'max:100000'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:999999'],
        ]);
        $data['price'] = isset($data['price']) && $data['price'] !== '' ? (string) $data['price'] : null;

        return $data;
    }
}
