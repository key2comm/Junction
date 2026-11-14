<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BillingItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Billing → Billing items: the shared catalog of things that can go on a bill. */
class BillingItemController extends Controller
{
    public function index(): Response
    {
        $used = DB::table('account_charges')->groupBy('billing_item_id')->selectRaw('billing_item_id, count(DISTINCT account_id) AS n')->pluck('n', 'billing_item_id');
        $inProfiles = DB::table('billing_profile_items')->groupBy('billing_item_id')->selectRaw('billing_item_id, count(*) AS n')->pluck('n', 'billing_item_id');

        return Inertia::render('billing/Items', [
            'items' => BillingItem::query()->orderBy('name')->get()->map(fn (BillingItem $i) => [
                ...$i->only(['id', 'name', 'description', 'unit', 'frequency', 'active']),
                'price' => (string) $i->price,
                'customers' => (int) ($used[$i->id] ?? 0),
                'profiles' => (int) ($inProfiles[$i->id] ?? 0),
            ]),
            'frequencies' => BillingItem::FREQUENCIES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        BillingItem::create($this->validated($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Billing item added.']);

        return to_route('billing-items.index');
    }

    /** Price changes apply from the next charge; customers with their own price keep it. */
    public function update(Request $request, BillingItem $billingItem): RedirectResponse
    {
        $billingItem->update($this->validated($request, $billingItem));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Billing item saved.']);

        return to_route('billing-items.index');
    }

    public function destroy(BillingItem $billingItem): RedirectResponse
    {
        if (DB::table('account_charges')->where('billing_item_id', $billingItem->id)->exists()
            || DB::table('billing_profile_items')->where('billing_item_id', $billingItem->id)->exists()) {
            return back()->withErrors(['item' => "{$billingItem->name} is on a customer's bill or in a profile. Take it off those first, or untick Active to stop offering it."]);
        }
        $billingItem->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Billing item deleted.']);

        return to_route('billing-items.index');
    }

    private function validated(Request $request, ?BillingItem $item = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('billing_items', 'name')->ignore($item)],
            'description' => ['nullable', 'string', 'max:255'],
            'unit' => ['nullable', 'string', 'max:32'],
            'price' => ['required', 'numeric', 'min:0', 'max:999999'],
            'frequency' => ['required', Rule::in(array_keys(BillingItem::FREQUENCIES))],
            'active' => ['sometimes', 'boolean'],
        ]);
        $data['unit'] = trim((string) ($data['unit'] ?? '')) ?: 'each';
        $data['active'] = $request->boolean('active', $item?->active ?? true);

        return $data;
    }
}
