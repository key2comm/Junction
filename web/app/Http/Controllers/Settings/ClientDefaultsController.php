<?php

namespace App\Http\Controllers\Settings;

use App\Enums\AccountStatus;
use App\Enums\BillingType;
use App\Http\Controllers\Controller;
use App\Models\BillingProfile;
use App\Models\RateDeck;
use App\Models\RoutingPlan;
use App\Support\ClientDefaults;
use App\Support\Currencies;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Settings → Client defaults: what the "New customer" form starts with. */
class ClientDefaultsController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('settings/ClientDefaults', [
            'defaults' => ClientDefaults::get(),
            'options' => [
                'statuses' => AccountStatus::values(),
                'billingTypes' => BillingType::values(),
                'rateDecks' => RateDeck::query()->where('kind', 'tariff')->orderBy('name')->get(['id', 'name', 'currency']),
                'routingPlans' => RoutingPlan::query()->orderBy('name')->get(['id', 'name']),
                'billingProfiles' => BillingProfile::query()->orderBy('name')->get(['id', 'name']),
                'timezones' => timezone_identifiers_list(),
                'currencies' => Currencies::options(),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        ClientDefaults::save($request->validate(ClientDefaults::rules(), ['sell_rate_deck_id.exists' => 'Choose a tariff.']));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Client defaults saved. New customers start with these.']);

        return to_route('client-defaults.edit');
    }
}
