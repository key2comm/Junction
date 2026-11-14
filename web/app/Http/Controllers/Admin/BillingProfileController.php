<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BillingItem;
use App\Models\BillingProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Billing → Billing profiles: named plans (items + how Canada/USA calls are
 * charged) given to customers. A profile's items are copied onto a customer's
 * bill when it's assigned; editing the profile later changes its Canada/USA
 * plan for everyone on it, but not the items already on their bills.
 */
class BillingProfileController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('billing/Profiles', [
            'profiles' => BillingProfile::query()->withCount('accounts')->with('items.item')->orderBy('name')->get()->map(fn (BillingProfile $p) => [
                ...$p->only(['id', 'name', 'description', 'nanp_plan', 'nanp_minutes', 'accounts_count']),
                'plan' => $p->planLabel(),
                'items' => $p->items->map(fn ($pi) => [
                    'name' => $pi->item->name, 'unit' => $pi->item->unit, 'frequency' => $pi->item->frequency,
                    'quantity' => (string) $pi->quantity, 'price' => (string) ($pi->price ?? $pi->item->price),
                ]),
            ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('billing/ProfileForm', [...$this->options(), 'profile' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        DB::transaction(function () use ($data) {
            $profile = BillingProfile::create($data['profile']);
            $profile->items()->createMany($data['items']);
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Billing profile created.']);

        return to_route('billing-profiles.index');
    }

    public function edit(BillingProfile $billingProfile): Response
    {
        return Inertia::render('billing/ProfileForm', [
            ...$this->options(),
            'profile' => [
                ...$billingProfile->only(['id', 'name', 'description', 'nanp_plan', 'nanp_minutes']),
                'accounts_count' => $billingProfile->accounts()->count(),
                'items' => $billingProfile->items->map(fn ($pi) => [
                    'billing_item_id' => $pi->billing_item_id, 'quantity' => (string) $pi->quantity, 'price' => $pi->price === null ? '' : (string) $pi->price,
                ]),
            ],
        ]);
    }

    public function update(Request $request, BillingProfile $billingProfile): RedirectResponse
    {
        $data = $this->validated($request, $billingProfile);
        DB::transaction(function () use ($billingProfile, $data) {
            $billingProfile->update($data['profile']);
            $billingProfile->items()->delete();
            $billingProfile->items()->createMany($data['items']);
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Billing profile saved.']);

        return to_route('billing-profiles.index');
    }

    public function destroy(BillingProfile $billingProfile): RedirectResponse
    {
        if ($billingProfile->accounts()->exists()) {
            return back()->withErrors(['profile' => "Customers are on {$billingProfile->name}. Move them to another profile first."]);
        }
        $billingProfile->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Billing profile deleted.']);

        return to_route('billing-profiles.index');
    }

    private function options(): array
    {
        return [
            'plans' => BillingProfile::PLANS,
            'items' => BillingItem::query()->orderBy('name')->get(['id', 'name', 'unit', 'price', 'frequency', 'active']),
        ];
    }

    /** @return array{profile: array, items: list<array>} */
    private function validated(Request $request, ?BillingProfile $profile = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('billing_profiles', 'name')->ignore($profile)],
            'description' => ['nullable', 'string', 'max:255'],
            'nanp_plan' => ['required', Rule::in(array_keys(BillingProfile::PLANS))],
            'nanp_minutes' => ['nullable', 'required_if:nanp_plan,bundle', 'integer', 'min:1', 'max:100000000'],
            'items' => ['array', 'max:50'],
            'items.*.billing_item_id' => ['required', 'integer', 'exists:billing_items,id', 'distinct'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01', 'max:100000'],
            'items.*.price' => ['nullable', 'numeric', 'min:0', 'max:999999'],
        ], ['nanp_minutes.required_if' => 'Enter how many minutes are included each month.', 'items.*.billing_item_id.distinct' => 'Each item can only be in the profile once.']);

        return [
            'profile' => [
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'nanp_plan' => $data['nanp_plan'],
                'nanp_minutes' => $data['nanp_plan'] === 'bundle' ? (int) $data['nanp_minutes'] : null,
            ],
            'items' => array_map(fn ($i) => [
                'billing_item_id' => (int) $i['billing_item_id'],
                'quantity' => $i['quantity'],
                'price' => isset($i['price']) && $i['price'] !== '' ? $i['price'] : null,
            ], array_values($data['items'] ?? [])),
        ];
    }
}
