<?php

namespace Tests\Feature\Routing;

use App\Models\Account;
use App\Models\AccountIp;
use App\Models\BlockedPrefix;
use App\Models\Gateway;
use App\Models\Rate;
use App\Models\RateDeck;
use App\Models\RoutingPlan;
use App\Models\RoutingPlanRoute;
use App\Models\Trunk;
use App\Services\Ledger;
use App\Services\Router;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The softswitch_route() / softswitch_identify() PostgreSQL functions: the
 * same code FreeSWITCH runs for every call.
 */
class RouterTest extends TestCase
{
    use RefreshDatabase;

    private Account $customer;

    private RoutingPlan $plan;

    private RateDeck $sell;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sell = RateDeck::factory()->create();
        $this->rate($this->sell, '44', '0.050');
        $this->plan = RoutingPlan::factory()->create(['mode' => 'lcr', 'max_attempts' => 3]);
        $this->customer = Account::factory()->create([
            'sell_rate_deck_id' => $this->sell->id,
            'routing_plan_id' => $this->plan->id,
        ]);
        app(Ledger::class)->payment($this->customer, '100', 'Opening balance');
    }

    private function rate(RateDeck $deck, string $prefix, string $rate, array $extra = []): Rate
    {
        return Rate::factory()->for($deck)->create(['prefix' => $prefix, 'rate' => $rate, 'initial_increment' => 60, 'increment' => 60, ...$extra]);
    }

    /** A vendor trunk with one gateway and a buy rate, routed in the plan. */
    private function vendor(string $name, string $buyRate, array $route = [], array $trunk = [], string $prefix = '44'): Trunk
    {
        $deck = RateDeck::factory()->create();
        $this->rate($deck, $prefix, $buyRate);
        $t = Trunk::factory()->create(['name' => $name, 'rate_deck_id' => $deck->id, ...$trunk]);
        Gateway::factory()->for($t)->create(['name' => Str::slug($name).'-gw']);
        RoutingPlanRoute::factory()->create(['routing_plan_id' => $this->plan->id, 'trunk_id' => $t->id, 'prefix' => '', ...$route]);

        return $t;
    }

    private function route(string $number = '442079460000', ?string $uuid = null): array
    {
        return app(Router::class)->route($this->customer->fresh(), $number, $uuid);
    }

    private function order(array $result): array
    {
        return array_map(fn ($a) => $a['gateways'][0]['name'], $result['attempts']);
    }

    public function test_lcr_orders_vendors_by_buy_rate(): void
    {
        $this->vendor('Pricey', '0.030');
        $this->vendor('Cheap', '0.010');
        $this->vendor('Middle', '0.020');

        $result = $this->route('+44 20 7946 0000');

        $this->assertSame('ok', $result['status']);
        $this->assertSame('442079460000', $result['destination']);
        $this->assertSame(['cheap-gw', 'middle-gw', 'pricey-gw'], $this->order($result));
        $this->assertSame('0.050000', $result['sell_rate']);
    }

    public function test_priority_mode_ignores_cost_and_attempts_are_capped(): void
    {
        $this->plan->update(['mode' => 'priority', 'max_attempts' => 2]);
        $this->vendor('First', '0.040', ['priority' => 1]);
        $this->vendor('Second', '0.001', ['priority' => 2]);
        $this->vendor('Third', '0.002', ['priority' => 3]);

        $this->assertSame(['first-gw', 'second-gw'], $this->order($this->route()));
    }

    public function test_weight_mode_spreads_calls_by_weight(): void
    {
        $this->plan->update(['mode' => 'weight', 'max_attempts' => 1]);
        $this->vendor('Heavy', '0.01', ['weight' => 900]);
        $this->vendor('Light', '0.01', ['weight' => 100]);

        $first = collect(range(1, 300))->map(fn () => $this->order($this->route())[0])->countBy();

        $this->assertGreaterThan(200, $first['heavy-gw']);   // ~270 expected
        $this->assertGreaterThan(5, $first['light-gw'] ?? 0); // ~30 expected
    }

    public function test_longest_routed_prefix_wins(): void
    {
        $this->rate($this->sell, '447', '0.090');
        $this->vendor('Anywhere', '0.010', ['prefix' => '']);
        $this->vendor('Mobile', '0.060', ['prefix' => '447'], prefix: '447');

        $this->assertSame(['mobile-gw'], $this->order($this->route('447700900123')));
        $this->assertSame(['anywhere-gw'], $this->order($this->route('442079460000')));
    }

    public function test_numbers_are_formatted_per_trunk(): void
    {
        $this->vendor('Tech', '0.01', trunk: ['tech_prefix' => '777#', 'strip_digits' => 2]);

        $this->assertSame('777#2079460000', $this->route()['attempts'][0]['dial_number']);
    }

    public function test_vendors_costing_more_than_the_sell_rate_are_skipped(): void
    {
        $this->vendor('Loss', '0.080');
        $this->assertSame('negative_margin', $this->route()['reason']);

        $this->vendor('Profit', '0.040');
        $this->assertSame(['profit-gw'], $this->order($this->route()));
    }

    public function test_unusable_trunks_are_skipped(): void
    {
        $this->vendor('Disabled', '0.001', trunk: ['enabled' => false]);
        $noGw = $this->vendor('NoGateway', '0.002');
        $noGw->gateways()->update(['enabled' => false]);
        $this->vendor('DisabledRoute', '0.003', ['enabled' => false]);
        $this->vendor('NoRate', '0.004', prefix: '33');

        $this->assertSame('no_route', $this->route()['reason']);

        $this->vendor('Good', '0.040');
        $this->assertSame(['good-gw'], $this->order($this->route()));
    }

    public function test_gateways_within_a_trunk_are_ordered_by_priority(): void
    {
        $t = $this->vendor('Multi', '0.01');
        $t->gateways()->update(['priority' => 2]);
        Gateway::factory()->for($t)->create(['name' => 'multi-primary', 'priority' => 1]);

        $this->assertSame(['multi-primary', 'multi-gw'], array_column($this->route()['attempts'][0]['gateways'], 'name'));
    }

    public function test_rejections_explain_why(): void
    {
        $this->vendor('V', '0.01');

        $this->assertSame('invalid_number', $this->route('abc')['reason']);
        $this->assertSame('no_sell_rate', $this->route('33123456789')['reason']);

        BlockedPrefix::factory()->create(['account_id' => null, 'prefix' => '4420']);
        $this->assertSame('blocked_destination', $this->route()['reason']);
        BlockedPrefix::query()->delete();

        $this->customer->update(['routing_plan_id' => null]);
        $this->assertSame('no_routing_plan', $this->route()['reason']);
        $this->customer->update(['routing_plan_id' => $this->plan->id, 'status' => 'suspended']);
        $this->assertSame('account_inactive', $this->route()['reason']);
    }

    public function test_max_duration_comes_from_available_funds_in_whole_increments(): void
    {
        $this->vendor('V', '0.01');
        // $100 at $0.05/min = 2000 minutes, capped at the 4h default.
        $this->assertSame(14400, $this->route()['max_duration']);

        // $0.10 at $0.05/min with 60/60 billing = 120s.
        DB::table('accounts')->where('id', $this->customer->id)->update(['balance' => '0.10']);
        $this->assertSame(120, $this->route()['max_duration']);

        // $0.04 cannot pay for the first 60s increment.
        DB::table('accounts')->where('id', $this->customer->id)->update(['balance' => '0.04']);
        $this->assertSame('insufficient_funds', $this->route()['reason']);

        // Postpaid credit counts as funds.
        $this->customer->update(['credit_limit' => '1']);
        $this->assertSame('ok', $this->route()['status']);
    }

    public function test_concurrent_calls_reserve_funds_and_release_them(): void
    {
        $this->vendor('V', '0.01');
        DB::table('accounts')->where('id', $this->customer->id)->update(['balance' => '0.25']);

        // $0.25 at $0.05/min: the first call may last 5 minutes and reserves all of it.
        $a = (string) Str::uuid();
        $this->assertSame(300, $this->route(uuid: $a)['max_duration']);
        $this->assertSame('insufficient_funds', $this->route(uuid: (string) Str::uuid())['reason']);

        // Retrying the same call doesn't double-reserve.
        $this->assertSame(300, $this->route(uuid: $a)['max_duration']);

        app(Router::class)->release($a);
        $this->assertSame('ok', $this->route(uuid: (string) Str::uuid())['status']);
    }

    public function test_unposted_usage_counts_against_funds(): void
    {
        $this->vendor('V', '0.01');
        DB::table('accounts')->where('id', $this->customer->id)->update(['balance' => '1.00']);
        DB::table('cdrs')->insert([
            'uuid' => (string) Str::uuid(), 'customer_account_id' => $this->customer->id, 'callee' => '44',
            'started_at' => now(), 'sell_cost' => '0.97', 'posted_at' => null,
        ]);

        $this->assertSame('insufficient_funds', $this->route()['reason']);
    }

    public function test_customers_are_identified_by_ip_and_tech_prefix(): void
    {
        $other = Account::factory()->create(['code' => 'OTHER']);
        AccountIp::factory()->for($this->customer)->create(['ip' => '198.51.100.0/24']);
        AccountIp::factory()->for($other)->create(['ip' => '198.51.100.0/24', 'tech_prefix' => '123#']);
        AccountIp::factory()->for($other)->create(['ip' => '203.0.113.9', 'enabled' => false]);

        $router = app(Router::class);
        $this->assertSame($this->customer->id, $router->identify('198.51.100.7', '442079460000')->account_id);

        $tech = $router->identify('198.51.100.7', '123#442079460000');
        $this->assertSame($other->id, $tech->account_id);
        $this->assertSame('442079460000', $tech->number);

        $this->assertNull($router->identify('203.0.113.9', '44'));   // disabled
        $this->assertNull($router->identify('192.0.2.1', '44'));     // unknown
    }

    public function test_freeswitch_role_can_only_execute_the_routing_functions(): void
    {
        $grants = collect(DB::select(
            "SELECT p.proname FROM pg_proc p WHERE p.proname LIKE 'softswitch_%' AND has_function_privilege('public', p.oid, 'EXECUTE')",
        ))->pluck('proname');

        $this->assertEmpty($grants, 'Routing functions must not be executable by PUBLIC: '.$grants->implode(', '));
    }
}
