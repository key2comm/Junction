<?php

namespace Tests\Feature\Admin;

use App\Models\Account;
use App\Models\AccountIp;
use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AccountsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
    }

    private function validAccount(array $overrides = []): array
    {
        return [
            'name' => 'Acme Telecom',
            'code' => 'acme01',
            'is_customer' => '1',
            'status' => 'active',
            'currency' => 'usd',
            'billing_type' => 'prepaid',
            'credit_limit' => '0',
            'timezone' => 'America/Toronto',
            ...$overrides,
        ];
    }

    public function test_guests_are_redirected(): void
    {
        $this->get(route('accounts.index'))->assertRedirect(route('login'));
        $this->post(route('accounts.store'), $this->validAccount())->assertRedirect(route('login'));
        $this->assertSame(0, Account::query()->count());
    }

    public function test_index_lists_and_filters_accounts(): void
    {
        Account::factory()->create(['name' => 'Alpha Voice', 'code' => 'ALPHA']);
        Account::factory()->vendor()->create(['name' => 'Beta Carrier', 'code' => 'BETA']);

        $this->actingAs($this->admin)->get(route('accounts.index'))
            ->assertInertia(fn (Assert $page) => $page->component('accounts/Index')->has('accounts.data', 2));

        $this->actingAs($this->admin)->get(route('accounts.index', ['role' => 'vendor']))
            ->assertInertia(fn (Assert $page) => $page->has('accounts.data', 1)->where('accounts.data.0.code', 'BETA'));

        $this->actingAs($this->admin)->get(route('accounts.index', ['q' => 'alp']))
            ->assertInertia(fn (Assert $page) => $page->has('accounts.data', 1)->where('accounts.data.0.code', 'ALPHA'));
    }

    public function test_an_account_can_be_created_with_normalised_code_and_currency(): void
    {
        $this->actingAs($this->admin)->post(route('accounts.store'), $this->validAccount())
            ->assertSessionHasNoErrors();

        $account = Account::query()->sole();
        $this->assertSame('ACME01', $account->code);
        $this->assertSame('USD', $account->currency);
        $this->assertTrue($account->is_customer);
        $this->assertFalse($account->is_vendor);
        $this->assertSame('0.000000', $account->balance);
    }

    public function test_account_validation(): void
    {
        Account::factory()->create(['code' => 'TAKEN']);

        $this->actingAs($this->admin)->post(route('accounts.store'), $this->validAccount(['is_customer' => null]))
            ->assertSessionHasErrors('is_customer');
        $this->actingAs($this->admin)->post(route('accounts.store'), $this->validAccount(['code' => 'taken']))
            ->assertSessionHasErrors('code');
        $this->actingAs($this->admin)->post(route('accounts.store'), $this->validAccount(['code' => 'no spaces']))
            ->assertSessionHasErrors('code');
        $this->actingAs($this->admin)->post(route('accounts.store'), $this->validAccount(['timezone' => 'Mars/Base']))
            ->assertSessionHasErrors('timezone');
        $this->actingAs($this->admin)->post(route('accounts.store'), $this->validAccount(['credit_limit' => '-5']))
            ->assertSessionHasErrors('credit_limit');

        $this->assertSame(1, Account::query()->count());
    }

    public function test_balance_cannot_be_set_through_the_form(): void
    {
        $this->actingAs($this->admin)->post(route('accounts.store'), $this->validAccount(['balance' => '1000000']));

        $this->assertSame('0.000000', Account::query()->sole()->balance);
    }

    public function test_an_account_can_be_updated(): void
    {
        $account = Account::factory()->create(['code' => 'OLD']);

        $this->actingAs($this->admin)
            ->put(route('accounts.update', $account), $this->validAccount(['code' => 'OLD', 'is_customer' => null, 'role' => 'carrier', 'billing_type' => 'postpaid', 'credit_limit' => '2500']))
            ->assertRedirect(route('accounts.show', $account));

        $account->refresh();
        $this->assertTrue($account->is_vendor);
        $this->assertFalse($account->is_customer);
        $this->assertSame('postpaid', $account->billing_type->value);
        $this->assertSame('2500.0000', $account->credit_limit);
    }

    public function test_currency_comes_from_the_list_with_usd_and_cad_first(): void
    {
        $this->actingAs($this->admin)->post(route('accounts.store'), $this->validAccount(['currency' => 'XYZ']))->assertSessionHasErrors('currency');

        $this->actingAs($this->admin)->get(route('accounts.create'))->assertInertia(fn (Assert $page) => $page
            ->where('options.currencies.0.code', 'USD')->where('options.currencies.1.code', 'CAD')->where('options.currencies.2.code', 'AED'));

        // No currency given (e.g. over the API): CAD.
        $this->actingAs($this->admin)->post(route('accounts.store'), $this->validAccount(['currency' => null]))->assertSessionHasNoErrors();
        $this->assertSame('CAD', Account::query()->sole()->currency);
    }

    public function test_an_account_is_either_a_customer_or_a_carrier(): void
    {
        $this->actingAs($this->admin)->post(route('accounts.store'), $this->validAccount(['is_vendor' => '1']))
            ->assertSessionHasErrors('is_customer');
        $this->actingAs($this->admin)->post(route('accounts.store'), $this->validAccount(['is_customer' => null]))
            ->assertSessionHasErrors('is_customer');
        $this->assertSame(0, Account::query()->count());

        $this->actingAs($this->admin)->post(route('accounts.store'), $this->validAccount(['is_customer' => null, 'role' => 'customer']))
            ->assertSessionHasNoErrors();
        $this->assertTrue(Account::query()->sole()->is_customer);
    }

    public function test_show_page_includes_ips_and_ledger(): void
    {
        $account = Account::factory()->create();
        AccountIp::factory()->for($account)->create(['ip' => '198.51.100.0/24']);

        $this->actingAs($this->admin)->get(route('accounts.show', $account))
            ->assertInertia(fn (Assert $page) => $page
                ->component('accounts/Show')
                ->where('account.code', $account->code)
                ->has('ips', 1)
                ->where('ips.0.ip', '198.51.100.0/24'));
    }

    public function test_customer_ips_are_validated_and_unique(): void
    {
        $account = Account::factory()->create();
        $other = Account::factory()->create(['code' => 'OTHER']);
        AccountIp::factory()->for($other)->create(['ip' => '203.0.113.9']);

        $post = fn (array $data) => $this->actingAs($this->admin)->post(route('accounts.ips.store', $account), $data);

        $post(['ip' => '198.51.100.0/24'])->assertSessionHasNoErrors();
        $post(['ip' => '198.51.100.7/24'])->assertSessionHasErrors('ip');   // host bits set
        $post(['ip' => '10.0.0.0/4'])->assertSessionHasErrors('ip');        // too broad
        $post(['ip' => 'not-an-ip'])->assertSessionHasErrors('ip');
        $post(['ip' => '203.0.113.9/32'])->assertSessionHasErrors('ip');    // same as 203.0.113.9, other account
        $post(['ip' => '203.0.113.9', 'tech_prefix' => '123#'])->assertSessionHasNoErrors();
        $post(['ip' => '192.0.2.1', 'tech_prefix' => '12a'])->assertSessionHasErrors('tech_prefix');

        $this->assertSame(2, $account->ips()->count());
    }

    public function test_ips_can_be_toggled_and_removed_only_through_their_own_account(): void
    {
        $account = Account::factory()->create();
        $ip = AccountIp::factory()->for($account)->create();
        $other = Account::factory()->create(['code' => 'OTHER']);

        $this->actingAs($this->admin)->patch(route('accounts.ips.update', [$account, $ip]), ['enabled' => '0']);
        $this->assertFalse($ip->fresh()->enabled);

        $this->actingAs($this->admin)->delete(route('accounts.ips.destroy', [$other, $ip]))->assertNotFound();
        $this->actingAs($this->admin)->delete(route('accounts.ips.destroy', [$account, $ip]))->assertRedirect();
        $this->assertModelMissing($ip);
    }

    public function test_payments_and_adjustments_update_the_balance(): void
    {
        $account = Account::factory()->create();
        $store = fn (array $data) => $this->actingAs($this->admin)->post(route('accounts.ledger.store', $account), $data);

        $store(['type' => 'payment', 'amount' => '250.00', 'description' => 'Wire #1001'])->assertSessionHasNoErrors();
        $store(['type' => 'adjustment', 'amount' => '-10.5', 'description' => 'Correction'])->assertSessionHasNoErrors();
        $store(['type' => 'payment', 'amount' => '-5', 'description' => 'Bad'])->assertSessionHasErrors('amount');
        $store(['type' => 'usage', 'amount' => '-5', 'description' => 'Not allowed from UI'])->assertSessionHasErrors('type');
        $store(['type' => 'payment', 'amount' => '0', 'description' => 'Zero'])->assertSessionHasErrors('amount');

        $this->assertSame('239.500000', $account->fresh()->balance);
        $this->assertSame(2, LedgerEntry::query()->count());
        $this->assertSame($this->admin->id, LedgerEntry::query()->first()->user_id);
    }

    public function test_route_tester_shows_the_routing_decision(): void
    {
        $sell = \App\Models\RateDeck::factory()->create();
        \App\Models\Rate::factory()->for($sell)->create(['prefix' => '44', 'rate' => '0.05']);
        $plan = \App\Models\RoutingPlan::factory()->create();
        $account = Account::factory()->create(['sell_rate_deck_id' => $sell->id, 'routing_plan_id' => $plan->id, 'credit_limit' => 10]);
        $buy = \App\Models\RateDeck::factory()->create();
        \App\Models\Rate::factory()->for($buy)->create(['prefix' => '44', 'rate' => '0.02']);
        $trunk = \App\Models\Trunk::factory()->create(['name' => 'Main', 'rate_deck_id' => $buy->id]);
        \App\Models\Gateway::factory()->for($trunk)->create(['name' => 'main-gw']);
        \App\Models\RoutingPlanRoute::factory()->create(['routing_plan_id' => $plan->id, 'trunk_id' => $trunk->id]);

        $this->actingAs($this->admin)->getJson(route('accounts.route-test', [$account, 'number' => '+44 20 7946 0000']))
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('attempts.0.gateways.0.name', 'main-gw')
            ->assertJsonPath('attempts.0.margin', '0.030000')
            ->assertJsonPath('attempts.0.trunk', $trunk->account->code.' / Main');

        $this->actingAs($this->admin)->getJson(route('accounts.route-test', [$account, 'number' => '33123']))
            ->assertJsonPath('reason', 'no_sell_rate');
        $this->actingAs($this->admin)->getJson(route('accounts.route-test', [$account, 'number' => "44'; drop table x"]))
            ->assertUnprocessable();

        // The tester never reserves funds.
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('active_calls')->count());
    }
}
