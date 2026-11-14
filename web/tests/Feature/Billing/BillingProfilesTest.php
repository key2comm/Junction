<?php

namespace Tests\Feature\Billing;

use App\Models\Account;
use App\Models\BillingCharge;
use App\Models\BillingItem;
use App\Models\BillingProfile;
use App\Models\Cdr;
use App\Models\Gateway;
use App\Models\Rate;
use App\Models\RateDeck;
use App\Models\RoutingPlan;
use App\Models\RoutingPlanRoute;
use App\Models\Trunk;
use App\Models\User;
use App\Services\BillingCharges;
use App\Services\CdrIngestor;
use App\Services\InvoiceGenerator;
use App\Services\Ledger;
use App\Services\Router;
use App\Support\ClientDefaults;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Billing → Billing items / profiles: plan charges, included minutes, invoices. */
class BillingProfilesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Account $customer;

    private Rate $nanp;

    private Rate $uk;

    private BillingItem $hosting;

    private BillingItem $setup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-16 12:00:00'); // 15 of November's 30 days left, today included
        $this->admin = User::factory()->create();

        $sell = RateDeck::factory()->create();
        $this->nanp = Rate::factory()->for($sell)->create(['prefix' => '1', 'rate' => '0.010', 'initial_increment' => 60, 'increment' => 60]);
        $this->uk = Rate::factory()->for($sell)->create(['prefix' => '44', 'rate' => '0.060', 'initial_increment' => 60, 'increment' => 60]);
        $plan = RoutingPlan::factory()->create();
        $buy = RateDeck::factory()->create();
        Rate::factory()->for($buy)->create(['prefix' => '1', 'rate' => '0.005', 'initial_increment' => 60, 'increment' => 60]);
        Rate::factory()->for($buy)->create(['prefix' => '44', 'rate' => '0.030', 'initial_increment' => 60, 'increment' => 60]);
        $trunk = Trunk::factory()->create(['rate_deck_id' => $buy->id]);
        Gateway::factory()->for($trunk)->create();
        RoutingPlanRoute::factory()->create(['routing_plan_id' => $plan->id, 'trunk_id' => $trunk->id]);

        $this->customer = Account::factory()->create(['sell_rate_deck_id' => $sell->id, 'routing_plan_id' => $plan->id, 'timezone' => 'UTC',
            'dialing_scope' => 'international', 'billing_type' => 'prepaid', 'credit_limit' => 0]);
        $this->hosting = BillingItem::create(['name' => 'Unlimited hosting', 'unit' => 'ext', 'price' => '20', 'frequency' => 'monthly']);
        $this->setup = BillingItem::create(['name' => 'Setup fee', 'price' => '50', 'frequency' => 'once']);
    }

    private function profile(string $plan = 'unlimited', ?int $minutes = null, string $qty = '10'): BillingProfile
    {
        $p = BillingProfile::create(['name' => 'Hosted '.Str::random(4), 'nanp_plan' => $plan, 'nanp_minutes' => $minutes]);
        $p->items()->create(['billing_item_id' => $this->hosting->id, 'quantity' => $qty]);
        $p->items()->create(['billing_item_id' => $this->setup->id, 'quantity' => 1]);

        return $p;
    }

    private function charges(): BillingCharges
    {
        return app(BillingCharges::class);
    }

    /** A rated call record from FreeSWITCH. */
    private function placeCall(string $number, int $billsec, ?string $at = null): Cdr
    {
        $start = CarbonImmutable::parse($at ?? now())->getTimestamp() * 1_000_000;
        $rate = str_starts_with($number, '44') ? $this->uk : $this->nanp;
        $vars = [
            'uuid' => (string) Str::uuid(), 'start_uepoch' => $start, 'answer_uepoch' => $start + 1_000_000,
            'end_uepoch' => $start + 1_000_000 + $billsec * 1_000_000, 'billsec' => $billsec, 'hangup_cause' => 'NORMAL_CLEARING',
            'softswitch_account_id' => $this->customer->id, 'softswitch_destination' => $number, 'softswitch_sell_rate_id' => $rate->id,
        ];
        $v = implode('', array_map(fn ($k, $val) => "<{$k}>".rawurlencode((string) $val)."</{$k}>", array_keys($vars), $vars));

        return app(CdrIngestor::class)->ingest("<?xml version=\"1.0\"?>\n<cdr><variables>{$v}</variables><callflow><caller_profile>"
            ."<destination_number>{$number}</destination_number></caller_profile></callflow></cdr>");
    }

    public function test_items_and_profiles_are_managed_from_the_billing_menu(): void
    {
        $as = $this->actingAs($this->admin);
        $as->post(route('billing-items.store'), ['name' => 'DID', 'unit' => 'DID', 'price' => '1.50', 'frequency' => 'monthly'])->assertRedirect();
        $did = BillingItem::query()->where('name', 'DID')->sole();
        $as->get(route('billing-items.index'))->assertInertia(fn (Assert $p) => $p->component('billing/Items')->has('items', 3));

        $as->post(route('billing-profiles.store'), ['name' => 'Bundle', 'nanp_plan' => 'bundle', 'items' => []])->assertSessionHasErrors('nanp_minutes');
        $as->post(route('billing-profiles.store'), ['name' => 'Bundle', 'nanp_plan' => 'bundle', 'nanp_minutes' => 5000,
            'items' => [['billing_item_id' => $this->hosting->id, 'quantity' => 5], ['billing_item_id' => $did->id, 'quantity' => 2, 'price' => '1']]])->assertRedirect(route('billing-profiles.index'));
        $profile = BillingProfile::query()->where('name', 'Bundle')->sole();
        $this->assertSame(5000, $profile->nanp_minutes);
        $this->assertSame('1.000000', (string) $profile->items()->where('billing_item_id', $did->id)->value('price'));
        $as->get(route('billing-profiles.index'))->assertInertia(fn (Assert $p) => $p->where('profiles.0.plan', '5,000 Canada/USA minutes a month'));

        // In use: neither can be deleted.
        $as->delete(route('billing-items.destroy', $did))->assertSessionHasErrors('item');
        $this->charges()->assignProfile($this->customer, $profile);
        $as->delete(route('billing-profiles.destroy', $profile))->assertSessionHasErrors('profile');

        // Client defaults: new customers start on a profile.
        $as->put(route('client-defaults.update'), [...ClientDefaults::get(), 'billing_profile_id' => $profile->id])->assertSessionHasNoErrors();
        $this->assertSame($profile->id, ClientDefaults::get()['billing_profile_id']);
    }

    public function test_a_profile_mid_month_charges_the_rest_of_the_month_and_one_time_items(): void
    {
        $this->charges()->assignProfile($this->customer, $this->profile());

        // 10 ext × 20 = 200 a month; 15 of 30 days = 100. Setup fee 50 once.
        $this->assertSame(['prorated' => '100.000000', 'once' => '50.000000'],
            BillingCharge::query()->pluck('amount', 'kind')->map(fn ($a) => (string) $a)->all());
        $this->assertSame('-150.000000', (string) $this->customer->fresh()->balance);
        $this->assertSame(1, BillingCharge::query()->where('period', '2026-11')->count());

        // Running the month again charges nothing more.
        $this->assertSame(0, $this->charges()->runMonthly());

        // Two more extensions: 2 × 20 × 15/30 = 20. Then removed the next day: credited 14/30.
        $line = $this->customer->charges()->where('billing_item_id', $this->hosting->id)->sole();
        $this->charges()->update($line, '12', null);
        $this->assertSame('-170.000000', (string) $this->customer->fresh()->balance);
        $this->travelTo('2026-11-17 09:00:00');
        $this->charges()->remove($line->fresh());
        // 12 × 20 × 14/30 = 112 back.
        $this->assertSame('-58.000000', (string) $this->customer->fresh()->balance);
        $this->assertSame(0, $this->customer->charges()->where('billing_item_id', $this->hosting->id)->count());
    }

    public function test_monthly_items_are_billed_in_advance_on_the_invoice_issued_at_the_month_end(): void
    {
        $this->charges()->assignProfile($this->customer, $this->profile());
        app(Ledger::class)->payment($this->customer, '500', 'Payment');
        $this->placeCall('442079460000', 120); // 2 min × 0.06 = 0.12

        // December starts: the invoice for November carries December's plan.
        $this->travelTo('2026-12-01 03:00:00');
        $invoice = app(InvoiceGenerator::class)->generate($this->customer->fresh(), ...InvoiceGenerator::monthPeriod($this->customer, CarbonImmutable::parse('2026-11-15')));

        $this->assertSame('350.000000', (string) $invoice->charges_total);   // 100 prorated + 50 setup + 200 December
        $this->assertSame('0.120000', (string) $invoice->usage_total);
        $this->assertSame(['Unlimited hosting × 10 ext — November 2026 (prorated, 15 of 30 days)', 'Setup fee × 1', 'Unlimited hosting × 10 ext — December 2026'],
            $invoice->charges()->pluck('description')->all());
        $this->assertSame('149.880000', (string) $invoice->closing_balance); // 500 − 350 − 0.12
        $this->assertSame((string) $this->customer->fresh()->balance, (string) $invoice->closing_balance);

        // The monthly run later in December finds it already charged.
        $this->assertSame(0, $this->charges()->runMonthly());

        $this->actingAs($this->admin)->get(route('invoices.show', $invoice))->assertInertia(fn (Assert $p) => $p
            ->has('charges', 3)->where('invoice.charges_total', '350.000000'));
    }

    public function test_unlimited_canada_usa_calls_are_free_and_international_ones_pay(): void
    {
        $this->charges()->assignProfile($this->customer, $this->profile('unlimited'));

        $us = $this->placeCall('12125550100', 600);
        $this->assertSame('0.000000', (string) $us->sell_cost);
        $this->assertSame(600, $us->included_seconds);

        $uk = $this->placeCall('442079460000', 60);
        $this->assertSame('0.060000', (string) $uk->sell_cost);
        $this->assertSame(0, $uk->included_seconds);

        // Jamaica is +1 but international.
        $this->assertSame(0, $this->placeCall('18765550100', 60)->included_seconds);
    }

    public function test_included_minutes_run_out_and_the_rest_of_the_call_pays(): void
    {
        $this->charges()->assignProfile($this->customer, $this->profile('bundle', 10));

        $this->assertSame('0.000000', (string) $this->placeCall('14165550100', 480)->sell_cost); // 8 of 10 minutes
        $crossing = $this->placeCall('14165550100', 300);                                         // 2 included, 3 paid
        $this->assertSame(120, $crossing->included_seconds);
        $this->assertSame('0.030000', (string) $crossing->sell_cost);
        $this->assertSame('0.020000', (string) $this->placeCall('14165550100', 120)->sell_cost);  // all paid

        // Next month starts with a full bundle again.
        $this->assertSame(60, $this->placeCall('14165550100', 60, '2026-12-02 10:00:00')->included_seconds);

        $this->actingAs($this->admin)->get(route('accounts.show', $this->customer))->assertInertia(fn (Assert $p) => $p
            ->where('plan.profile.nanp_plan', 'bundle')->where('plan.included_used', 600)->has('plan.charges', 2));
    }

    public function test_routing_holds_no_funds_for_calls_the_plan_covers(): void
    {
        // No money at all.
        $route = fn (string $n) => app(Router::class)->route($this->customer->fresh(), $n);
        $this->assertSame('insufficient_funds', $route('12125550100')['reason']);

        $this->charges()->assignProfile($this->customer, $this->profile('unlimited', null, '1'));
        // The plan's charges took the balance below zero; Canada/USA still goes through, the UK doesn't.
        $this->assertSame('ok', $route('12125550100')['status']);
        $this->assertSame('insufficient_funds', $route('442079460000')['reason']);

        // Included minutes: covered while some are left.
        $bundle = $this->profile('bundle', 1, '1');
        $this->charges()->assignProfile($this->customer, $bundle);
        $this->assertSame('ok', $route('12125550100')['status']);
        $this->placeCall('12125550100', 60);
        $this->assertSame('insufficient_funds', $route('12125550100')['reason']);
    }

    public function test_the_customer_page_manages_the_plan(): void
    {
        $profile = $this->profile();
        $as = $this->actingAs($this->admin);
        $as->put(route('accounts.billing-profile.update', $this->customer), ['billing_profile_id' => $profile->id])->assertRedirect();
        $this->assertSame($profile->id, $this->customer->fresh()->billing_profile_id);

        $did = BillingItem::create(['name' => 'DID', 'unit' => 'DID', 'price' => '2', 'frequency' => 'monthly']);
        $as->post(route('accounts.charges.store', $this->customer), ['billing_item_id' => $did->id, 'quantity' => 3, 'price' => '1.5'])->assertRedirect();
        $line = $this->customer->charges()->where('billing_item_id', $did->id)->sole();
        $this->assertSame('1.500000', (string) $line->price);
        $as->patch(route('accounts.charges.update', [$this->customer, $line]), ['quantity' => 4])->assertRedirect();
        $this->assertSame('4.00', (string) $line->fresh()->quantity);
        $this->assertNull($line->fresh()->price);

        // Another customer's line can't be reached through this one.
        $other = Account::factory()->create();
        $as->delete(route('accounts.charges.destroy', [$other, $line]))->assertNotFound();
        $as->delete(route('accounts.charges.destroy', [$this->customer, $line]))->assertRedirect();

        // Taking the profile off removes its items (not the extra ones).
        $as->post(route('accounts.charges.store', $this->customer), ['billing_item_id' => $did->id, 'quantity' => 1])->assertRedirect();
        $as->put(route('accounts.billing-profile.update', $this->customer), ['billing_profile_id' => null])->assertRedirect();
        $this->assertSame([$did->id], $this->customer->charges()->pluck('billing_item_id')->all());

        // New customers can start on a profile.
        $as->post(route('accounts.store'), [
            'name' => 'New Co', 'code' => 'NEWCO', 'role' => 'customer', 'status' => 'active', 'currency' => 'CAD', 'billing_type' => 'prepaid',
            'credit_limit' => 0, 'timezone' => 'UTC', 'billing_profile_id' => $profile->id,
        ])->assertSessionHasNoErrors();
        $this->assertSame(2, Account::query()->where('code', 'NEWCO')->sole()->charges()->count());
    }
}
