<?php

namespace Tests\Feature\Billing;

use App\Models\Account;
use App\Models\ApiToken;
use App\Models\Cdr;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Rate;
use App\Models\RateDeck;
use App\Models\RoutingPlan;
use App\Models\RoutingPlanRoute;
use App\Models\Trunk;
use App\Models\User;
use App\Services\InvoiceGenerator;
use App\Services\Router;
use App\Support\Billing;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

/**
 * Settings → Billing: the built-in billing can be turned off for switches that
 * only route calls (another platform bills the customers).
 */
class BillingSwitchTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
    }

    private function off(): void
    {
        Settings::put([Billing::ENABLED => false]);
    }

    private function confirmed(): array
    {
        return ['auth.password_confirmed_at' => time()];
    }

    /** A customer with a sell rate and one vendor routed for 44. */
    private function routedCustomer(array $attrs = []): Account
    {
        $sell = RateDeck::factory()->create();
        Rate::factory()->for($sell)->create(['prefix' => '44', 'rate' => '0.05', 'initial_increment' => 60, 'increment' => 60]);
        $buy = RateDeck::factory()->create();
        Rate::factory()->for($buy)->create(['prefix' => '44', 'rate' => '0.01', 'initial_increment' => 60, 'increment' => 60]);
        $plan = RoutingPlan::factory()->create(['mode' => 'lcr']);
        $trunk = Trunk::factory()->create(['rate_deck_id' => $buy->id]);
        Gateway::factory()->for($trunk)->create(['name' => 'v-gw']);
        RoutingPlanRoute::factory()->create(['routing_plan_id' => $plan->id, 'trunk_id' => $trunk->id, 'prefix' => '']);

        return Account::factory()->create(['sell_rate_deck_id' => $sell->id, 'routing_plan_id' => $plan->id, ...$attrs]);
    }

    public function test_billing_is_on_by_default(): void
    {
        $this->assertTrue(Billing::enabled());
        $this->assertTrue((bool) DB::selectOne('SELECT softswitch_billing_enabled() AS on')->on);
    }

    public function test_with_billing_off_routing_skips_the_funds_check_but_still_uses_rates(): void
    {
        $customer = $this->routedCustomer(['credit_limit' => 0]);
        DB::table('accounts')->where('id', $customer->id)->update(['balance' => '-50']);
        $router = app(Router::class);

        $this->assertSame('insufficient_funds', $router->route($customer->fresh(), '442079460000')['reason']);

        $this->off();
        $this->assertFalse((bool) DB::selectOne('SELECT softswitch_billing_enabled() AS on')->on);

        $uuid = (string) Str::uuid();
        $result = $router->route($customer->fresh(), '442079460000', $uuid);
        $this->assertSame('ok', $result['status']);
        $this->assertSame(14400, $result['max_duration']);          // the full maximum, not limited by funds
        $this->assertEquals(0.01, (float) $result['attempts'][0]['buy_rate']); // LCR still uses buy rates
        // The call still shows as in progress, but reserves nothing.
        $this->assertEquals(0, (float) DB::table('active_calls')->where('uuid', $uuid)->value('reserved'));

        // Sell rates still decide what a customer may call.
        $this->assertSame('no_sell_rate', $router->route($customer->fresh(), '33123456789')['reason']);
    }

    public function test_settings_page_turns_billing_off_and_on(): void
    {
        $this->actingAs($this->admin)->get(route('billing.edit'))->assertInertia(fn (Assert $page) => $page
            ->component('settings/Billing')->where('settings.enabled', true)->where('settings.invoice_due_days', 15)->where('billing', true));

        // Requires a recent password confirmation.
        $this->actingAs($this->admin)->put(route('billing.update'), ['enabled' => '0'])->assertRedirect(route('password.confirm'));

        $this->actingAs($this->admin)->withSession($this->confirmed())->put(route('billing.update'), ['enabled' => '0'])
            ->assertRedirect(route('billing.edit'))->assertSessionHasNoErrors();
        $this->assertFalse(Billing::enabled());

        $this->actingAs($this->admin)->withSession($this->confirmed())
            ->put(route('billing.update'), ['enabled' => '1', 'invoice_due_days' => 30, 'auto_email_invoices' => '1'])->assertSessionHasNoErrors();
        $this->assertTrue(Billing::enabled());
        $this->assertSame(30, Billing::dueDays());
        $this->assertTrue(Billing::autoEmailInvoices());

        // Turning it off keeps the invoice options for later.
        $this->actingAs($this->admin)->withSession($this->confirmed())->put(route('billing.update'), ['enabled' => '0']);
        $this->assertSame(30, Billing::dueDays());
        $this->assertTrue(Billing::autoEmailInvoices());

        $this->actingAs($this->admin)->withSession($this->confirmed())->put(route('billing.update'), ['enabled' => '1', 'invoice_due_days' => 999])
            ->assertSessionHasErrors('invoice_due_days');
    }

    public function test_portal_users_cannot_change_billing_settings(): void
    {
        $customer = Account::factory()->create();
        $user = User::factory()->create(['account_id' => $customer->id]);

        $this->actingAs($user)->get(route('billing.edit'))->assertRedirect();
        $status = $this->actingAs($user)->withSession($this->confirmed())->put(route('billing.update'), ['enabled' => '0'])->status();
        $this->assertContains($status, [302, 403]);
        $this->assertTrue(Billing::enabled());
    }

    public function test_with_billing_off_billing_pages_and_api_are_unavailable(): void
    {
        $customer = Account::factory()->create();
        $portalUser = User::factory()->create(['account_id' => $customer->id]);
        [, $plain] = ApiToken::issue('test', array_keys(ApiToken::ABILITIES), $this->admin);
        $api = ['Authorization' => "Bearer {$plain}", 'Accept' => 'application/json'];

        $this->actingAs($this->admin)->get(route('invoices.index'))->assertOk();
        $this->withHeaders($api)->getJson('/api/v1/invoices')->assertOk();
        $this->flushHeaders();

        $this->off();

        $this->actingAs($this->admin)->get(route('invoices.index'))->assertRedirect(route('dashboard'));
        $this->actingAs($this->admin)->post(route('accounts.ledger.store', $customer), ['type' => 'payment', 'amount' => 10, 'description' => 'x'])
            ->assertRedirect(route('dashboard'));
        $this->assertSame(0, $customer->ledgerEntries()->count());
        $this->actingAs($portalUser)->get(route('portal.invoices.index'))->assertRedirect(route('portal.home'));

        // Pages know, so they can hide billing figures and menu items.
        $this->actingAs($this->admin)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('billing', false));
        $this->actingAs($portalUser)->get(route('portal.home'))->assertInertia(fn (Assert $page) => $page->where('billing', false)->where('invoices', []));

        $this->withHeaders($api)->getJson('/api/v1/invoices')->assertNotFound();
        $this->withHeaders($api)->getJson("/api/v1/accounts/{$customer->id}/ledger")->assertNotFound();
        $this->withHeaders($api)->getJson("/api/v1/accounts/{$customer->id}")->assertOk(); // accounts themselves are still managed
    }

    public function test_with_billing_off_no_invoices_or_low_balance_alerts_are_sent(): void
    {
        Mail::fake();
        Settings::put(['mail.host' => 'smtp.example.com', 'mail.port' => 587, 'mail.security' => 'starttls',
            'mail.from_address' => 'billing@switch.example', 'mail.auto_invoices' => true]);
        $this->travelTo(CarbonImmutable::parse('2026-11-03 12:00', 'UTC'));
        $customer = Account::factory()->create(['timezone' => 'UTC', 'billing_email' => 'ap@cust.example', 'low_balance_threshold' => 10]);
        Cdr::factory()->create(['uuid' => (string) Str::uuid(), 'customer_account_id' => $customer->id, 'callee' => '44', 'started_at' => '2026-10-10 10:00:00+00',
            'answered_at' => '2026-10-10 10:00:00+00', 'billsec' => 60, 'attempts' => 1, 'sell_prefix' => '44', 'sell_destination' => 'UK', 'sell_rate' => '0.05', 'sell_cost' => '0.05']);
        $this->off();

        $this->artisan('softswitch:invoices')->assertSuccessful();
        $this->artisan('softswitch:check-balances')->assertSuccessful();
        $this->assertSame(0, Invoice::query()->where('account_id', $customer->id)->count());
        $this->assertNull($customer->fresh()->low_balance_alerted_at);
        Mail::assertNothingSent();

        [$start, $end] = InvoiceGenerator::monthPeriod($customer, CarbonImmutable::parse('2026-10-15'));
        $this->expectException(RuntimeException::class);
        app(InvoiceGenerator::class)->generate($customer, $start, $end);
    }
}
