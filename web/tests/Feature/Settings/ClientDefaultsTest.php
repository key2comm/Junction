<?php

namespace Tests\Feature\Settings;

use App\Models\RateDeck;
use App\Models\RoutingPlan;
use App\Models\User;
use App\Support\ClientDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ClientDefaultsTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_are_saved_and_fill_the_new_customer_form(): void
    {
        $admin = User::factory()->create();
        $tariff = RateDeck::factory()->tariff()->create(['currency' => 'USD']);
        $carrierRates = RateDeck::factory()->create();
        $plan = RoutingPlan::factory()->create();

        $this->actingAs($admin)->get(route('client-defaults.edit'))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('settings/ClientDefaults')->where('defaults.currency', 'CAD')->where('defaults.sell_rate_deck_id', null));

        $form = ['status' => 'active', 'currency' => 'USD', 'billing_type' => 'postpaid', 'credit_limit' => '250', 'low_balance_threshold' => '20',
            'timezone' => 'America/Toronto', 'sell_rate_deck_id' => $tariff->id, 'routing_plan_id' => $plan->id, 'max_channels' => '30', 'max_cps' => '5'];
        $this->actingAs($admin)->put(route('client-defaults.update'), [...$form, 'sell_rate_deck_id' => $carrierRates->id])->assertSessionHasErrors('sell_rate_deck_id');
        $this->actingAs($admin)->put(route('client-defaults.update'), $form)->assertSessionHasNoErrors()->assertRedirect(route('client-defaults.edit'));

        $this->assertSame([...$form, 'sell_rate_deck_id' => $tariff->id, 'routing_plan_id' => $plan->id, 'max_channels' => 30, 'billing_profile_id' => null, 'tax_region' => null], ClientDefaults::get());

        $this->actingAs($admin)->get(route('accounts.create'))->assertInertia(fn (Assert $p) => $p
            ->where('defaults.sell_rate_deck_id', $tariff->id)->where('defaults.routing_plan_id', $plan->id)
            ->where('defaults.max_channels', 30)->where('defaults.billing_type', 'postpaid')->where('defaults.currency', 'USD'));
        // Carriers don't use client defaults.
        $this->actingAs($admin)->get(route('carriers.create'))->assertInertia(fn (Assert $p) => $p->where('defaults', null));

        // A deleted LCR group is no longer offered as the default.
        $plan->delete();
        $this->assertNull(ClientDefaults::get()['routing_plan_id']);
    }
}
