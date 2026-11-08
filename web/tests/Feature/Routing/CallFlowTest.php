<?php

namespace Tests\Feature\Routing;

use App\Jobs\SyncSwitchConfig;
use App\Models\Account;
use App\Models\AccountIp;
use App\Models\Cdr;
use App\Models\PbxDid;
use App\Models\Gateway;
use App\Models\LedgerEntry;
use App\Models\Rate;
use App\Models\RateDeck;
use App\Models\RoutingPlan;
use App\Models\RoutingPlanRoute;
use App\Models\Trunk;
use App\Services\FirewallSync;
use App\Services\Freeswitch\EventSocket;
use App\Services\Freeswitch\GatewaySync;
use App\Services\Ledger;
use App\Services\Router;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * What happens around a call: CDR ingestion and rating, usage posting,
 * gateway/firewall sync, and the internal CDR endpoint.
 */
class CallFlowTest extends TestCase
{
    use RefreshDatabase;

    private Account $customer;

    private Trunk $trunk;

    private Gateway $gateway;

    private Rate $sell;

    private Rate $buy;

    protected function setUp(): void
    {
        parent::setUp();
        config(['softswitch.internal.password' => 'secret-pw']);

        $sellDeck = RateDeck::factory()->create();
        $this->sell = Rate::factory()->for($sellDeck)->create(['prefix' => '44', 'rate' => '0.060', 'initial_increment' => 6, 'increment' => 6]);
        $buyDeck = RateDeck::factory()->create();
        $this->buy = Rate::factory()->for($buyDeck)->create(['prefix' => '44', 'rate' => '0.030', 'initial_increment' => 60, 'increment' => 60]);

        $plan = RoutingPlan::factory()->create();
        $this->customer = Account::factory()->create(['sell_rate_deck_id' => $sellDeck->id, 'routing_plan_id' => $plan->id]);
        app(Ledger::class)->payment($this->customer, '10', 'Opening');
        $this->trunk = Trunk::factory()->create(['rate_deck_id' => $buyDeck->id]);
        $this->gateway = Gateway::factory()->for($this->trunk)->create(['name' => 'vendor-a']);
        RoutingPlanRoute::factory()->create(['routing_plan_id' => $plan->id, 'trunk_id' => $this->trunk->id]);
    }

    /** A FreeSWITCH A-leg XML CDR as route.lua leaves it (values URL-encoded, as mod_xml_cdr sends them). */
    private function xml(string $uuid, array $vars = [], int $billsec = 65): string
    {
        $start = 1_793_000_000_000_000;
        $answered = $billsec > 0;
        $vars = [
            'uuid' => $uuid,
            'start_uepoch' => $start,
            'answer_uepoch' => $answered ? $start + 4_000_000 : 0,
            'end_uepoch' => $start + 4_000_000 + $billsec * 1_000_000,
            'billsec' => $billsec,
            'hangup_cause' => $answered ? 'NORMAL_CLEARING' : 'USER_BUSY',
            'softswitch_account_id' => $this->customer->id,
            'softswitch_destination' => '442079460000',
            'softswitch_sell_rate_id' => $this->sell->id,
            'softswitch_trunk_id' => $this->trunk->id,
            'softswitch_gateway_id' => $this->gateway->id,
            'softswitch_buy_rate_id' => $this->buy->id,
            'softswitch_attempts' => json_encode([
                ['trunk' => 99, 'gateway' => 98, 'buy_rate_id' => 1, 'buy_prefix' => '44', 'buy_rate' => '0.02', 'start' => 1793000000, 'end' => 1793000001, 'sip' => 503, 'cause' => 'NORMAL_TEMPORARY_FAILURE', 'answered' => false],
                ['trunk' => $this->trunk->id, 'gateway' => $this->gateway->id, 'buy_rate_id' => $this->buy->id, 'buy_prefix' => '44', 'buy_rate' => '0.03', 'start' => 1793000001, 'end' => 1793000070, 'sip' => 200, 'cause' => 'ANSWERED', 'answered' => true],
            ]),
            ...$vars,
        ];
        $v = implode('', array_map(fn ($k, $val) => "<{$k}>".rawurlencode((string) $val)."</{$k}>", array_keys($vars), $vars));

        return "<?xml version=\"1.0\"?>\n<cdr core-uuid=\"x\"><channel_data><state>CS_REPORTING</state></channel_data>"
            ."<variables>{$v}</variables><callflow><caller_profile><caller_id_number>15145550100</caller_id_number>"
            .'<destination_number>442079460000</destination_number><network_addr>198.51.100.7</network_addr></caller_profile></callflow></cdr>';
    }

    private function postCdr(string $xml, array $auth = ['freeswitch', 'secret-pw'], string $ip = '127.0.0.1')
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->withHeaders(['Authorization' => 'Basic '.base64_encode(implode(':', $auth))])
            ->post('/internal/freeswitch/cdr', ['cdr' => $xml]);
    }

    public function test_an_answered_call_is_rated_and_its_reservation_released(): void
    {
        $uuid = (string) Str::uuid();
        $this->assertSame('ok', app(Router::class)->route($this->customer, '442079460000', $uuid)['status']);
        $this->assertSame(1, DB::table('active_calls')->count());

        $this->postCdr($this->xml($uuid))->assertOk();

        $cdr = Cdr::query()->where('uuid', $uuid)->sole();
        $this->assertSame(65, $cdr->billsec);
        $this->assertSame('198.51.100.7', $cdr->source_ip);
        $this->assertSame('15145550100', $cdr->caller);
        // Sell 6/6: 66s at 0.06/min = 0.066. Buy 60/60: 120s at 0.03/min = 0.06.
        $this->assertSame('0.066000', $cdr->sell_cost);
        $this->assertSame('0.060000', $cdr->buy_cost);
        $this->assertSame('0.006000', $cdr->margin());
        $this->assertSame($this->trunk->account_id, $cdr->vendor_account_id);
        $this->assertSame(2, $cdr->attempts);
        $this->assertSame([503, 200], $cdr->attemptLog()->orderBy('attempt')->pluck('sip_code')->all());
        $this->assertSame(0, DB::table('active_calls')->count());
    }

    public function test_unanswered_calls_cost_nothing_and_have_no_vendor(): void
    {
        $this->postCdr($this->xml($uuid = (string) Str::uuid(), billsec: 0))->assertOk();

        $cdr = Cdr::query()->where('uuid', $uuid)->sole();
        $this->assertSame('0.000000', $cdr->sell_cost);
        $this->assertNull($cdr->buy_cost);
        $this->assertNull($cdr->vendor_account_id);
        $this->assertSame('USER_BUSY', $cdr->hangup_cause);
    }

    public function test_rejected_calls_record_the_reason(): void
    {
        $this->postCdr($this->xml($uuid = (string) Str::uuid(), [
            'softswitch_reject_reason' => 'insufficient_funds', 'softswitch_sip_code' => 402,
            'softswitch_attempts' => '', 'softswitch_trunk_id' => '', 'softswitch_buy_rate_id' => '',
        ], billsec: 0))->assertOk();

        $cdr = Cdr::query()->where('uuid', $uuid)->sole();
        $this->assertSame('insufficient_funds', $cdr->hangup_cause);
        $this->assertSame(402, $cdr->sip_code);
        $this->assertSame(0, $cdr->attempts);
    }

    public function test_pbx_calls_keep_their_direction_and_callee_legs_are_not_cdrs(): void
    {
        $this->postCdr($this->xml($out = (string) Str::uuid(), ['softswitch_direction' => 'pbx_out']))->assertOk();
        $this->assertSame('pbx_out', Cdr::query()->where('uuid', $out)->value('direction'));

        $local = (string) Str::uuid();
        $this->postCdr($this->xml($local, ['softswitch_direction' => 'pbx_local', 'softswitch_sell_rate_id' => '', 'softswitch_trunk_id' => '',
            'softswitch_gateway_id' => '', 'softswitch_buy_rate_id' => '', 'softswitch_attempts' => '[]']))->assertOk();
        $cdr = Cdr::query()->where('uuid', $local)->sole();
        $this->assertSame(['pbx_local', null, 0], [$cdr->direction, $cdr->sell_cost, $cdr->attempts]);

        // An inbound DID call shows the DID's provider as its carrier.
        $provider = Account::factory()->vendor()->create();
        $customer = Account::factory()->create(['is_customer' => true]);
        PbxDid::create(['account_id' => $customer->id, 'provider_account_id' => $provider->id, 'number' => '14163246990', 'destination' => ['type' => 'hangup']]);
        $this->postCdr($this->xml($in = (string) Str::uuid(), ['softswitch_direction' => 'pbx_in', 'softswitch_did' => '14163246990', 'softswitch_destination' => '14163246990',
            'softswitch_sell_rate_id' => '', 'softswitch_trunk_id' => '', 'softswitch_gateway_id' => '', 'softswitch_buy_rate_id' => '', 'softswitch_attempts' => '[]']))->assertOk();
        $cdr = Cdr::query()->where('uuid', $in)->sole();
        $this->assertSame(['pbx_in', $provider->id, null, null, '14163246990', 'incoming'], [$cdr->direction, $cdr->vendor_account_id, $cdr->buy_cost, $cdr->trunk_id, $cdr->did, $cdr->flow()]);
        $this->assertSame('outgoing', Cdr::query()->where('uuid', $out)->sole()->flow());
        $this->assertSame('internal', Cdr::query()->where('uuid', $local)->sole()->flow());
        $this->assertSame('incoming_forwarded', (new Cdr(['direction' => 'pbx_out', 'did' => '14163246990']))->flow());

        // The ringing extension's own leg, and anything unknown.
        $this->postCdr($this->xml($leg = (string) Str::uuid(), ['softswitch_pbx_leg' => 'true']))->assertOk();
        $this->assertFalse(Cdr::query()->where('uuid', $leg)->exists());
        $this->postCdr($this->xml($odd = (string) Str::uuid(), ['softswitch_direction' => 'sideways']))->assertOk();
        $this->assertSame('carrier', Cdr::query()->where('uuid', $odd)->value('direction'));
    }

    public function test_retried_posts_are_idempotent_and_posted_cdrs_are_never_rerated(): void
    {
        $uuid = (string) Str::uuid();
        $this->postCdr($this->xml($uuid))->assertOk();
        $this->postCdr($this->xml($uuid))->assertOk();
        $this->assertSame(1, Cdr::query()->count());
        $this->assertSame(2, DB::table('cdr_attempts')->count());

        $this->artisan('softswitch:post-usage')->assertSuccessful();
        $this->postCdr($this->xml($uuid, billsec: 600))->assertOk();
        $this->assertSame(65, Cdr::query()->sole()->billsec);
    }

    public function test_usage_is_posted_to_the_ledger_once(): void
    {
        $this->postCdr($this->xml((string) Str::uuid()))->assertOk();
        $this->postCdr($this->xml((string) Str::uuid(), billsec: 10))->assertOk(); // 12s billed = 0.012

        // Unposted usage already reduces what the customer can spend.
        $before = app(Router::class)->route($this->customer, '442079460000')['max_duration'];

        $this->artisan('softswitch:post-usage')->assertSuccessful();
        $this->artisan('softswitch:post-usage')->assertSuccessful();

        $usage = LedgerEntry::query()->where('type', 'usage')->sole();
        $this->assertSame('-0.078000', $usage->amount);
        $this->assertSame('9.922000', $this->customer->fresh()->balance);
        $this->assertSame(0, Cdr::query()->whereNull('posted_at')->count());
        $this->assertSame($before, app(Router::class)->route($this->customer, '442079460000')['max_duration']);
    }

    public function test_the_cdr_endpoint_is_loopback_only_and_authenticated(): void
    {
        $xml = $this->xml((string) Str::uuid());

        $this->postCdr($xml, ip: '203.0.113.5')->assertNotFound();
        $this->postCdr($xml, auth: ['freeswitch', 'wrong'])->assertUnauthorized();
        $this->postCdr('<not-a-cdr/>')->assertStatus(422);
        $this->assertSame(0, Cdr::query()->count());

        // The public site never serves /internal (nginx also blocks it).
        $this->postCdr($xml)->assertOk();
    }

    public function test_gateway_files_are_written_and_freeswitch_reloaded_only_on_change(): void
    {
        $dir = sys_get_temp_dir().'/ss-gw-'.Str::random(6);
        mkdir($dir);
        file_put_contents("{$dir}/stale-gw.xml", '<include/>');
        $this->gateway->update(['username' => 'acme', 'password' => 'p&ss"<x>', 'transport' => 'tcp', 'port' => 5070]);
        Gateway::factory()->for($this->trunk)->create(['name' => 'disabled-gw', 'enabled' => false]);

        $esl = Mockery::mock(EventSocket::class);
        $esl->shouldReceive('api')->with('sofia profile carrier killgw vendor-a')->once();
        $esl->shouldReceive('api')->with('sofia profile carrier killgw stale-gw')->once();
        $esl->shouldReceive('api')->with('reloadxml')->once();
        $esl->shouldReceive('api')->with('sofia profile carrier rescan')->once();
        $sync = new GatewaySync($esl, $dir);

        $result = $sync->sync();
        $this->assertSame(['vendor-a'], $result['written']);
        $this->assertSame(['stale-gw'], $result['removed']);
        $this->assertTrue($result['reloaded']);

        $xml = file_get_contents("{$dir}/vendor-a.xml");
        $this->assertStringContainsString('<gateway name="vendor-a">', $xml);
        $this->assertStringContainsString('value="'.$this->gateway->host.':5070;transport=tcp"', $xml);
        $this->assertStringContainsString('value="p&amp;ss&quot;&lt;x&gt;"', $xml); // escaped
        $this->assertFileDoesNotExist("{$dir}/disabled-gw.xml");
        $this->assertNotNull(simplexml_load_string($xml));

        // Nothing changed: no reload.
        $this->assertSame(1, $sync->sync()['unchanged']);

        array_map('unlink', glob("{$dir}/*"));
        rmdir($dir);
    }

    public function test_firewall_allowlist_covers_customers_and_vendor_hosts(): void
    {
        config(['softswitch.static_carrier_ips' => '192.0.2.50']);
        AccountIp::factory()->for($this->customer)->create(['ip' => '198.51.100.0/24']);
        AccountIp::factory()->for($this->customer)->create(['ip' => '198.51.100.9', 'enabled' => false]);
        $closed = Account::factory()->create(['code' => 'CLOSED', 'status' => 'closed']);
        AccountIp::factory()->for($closed)->create(['ip' => '203.0.113.77']);
        $this->gateway->update(['host' => 'sip.vendor.example']);
        Gateway::factory()->for($this->trunk)->create(['host' => '203.0.113.10']);

        $sync = new FirewallSync(fn (string $host) => $host === 'sip.vendor.example' ? ['203.0.113.20', '2001:db8::20'] : []);

        $this->assertSame(['192.0.2.50', '198.51.100.0/24', '2001:db8::20', '203.0.113.10', '203.0.113.20'], $sync->allowlist());

        Process::fake(['*' => Process::result('5 IPv4 and 0 IPv6 entries')]);
        $this->assertSame(['count' => 5], $sync->apply());
        Process::assertRan(fn ($p) => $p->command === ['sudo', '-n', '/usr/local/sbin/softswitch-firewall', 'apply']
            && str_contains($p->input, "198.51.100.0/24\n"));
    }

    public function test_carrier_changes_queue_a_switch_sync(): void
    {
        Queue::fake([SyncSwitchConfig::class]);

        AccountIp::factory()->for($this->customer)->create();
        Queue::assertPushed(SyncSwitchConfig::class);

        Queue::fake([SyncSwitchConfig::class]);
        $this->customer->update(['notes' => 'just a note']);
        Queue::assertNotPushed(SyncSwitchConfig::class);
        $this->customer->update(['status' => 'suspended']);
        Queue::assertPushed(SyncSwitchConfig::class);
    }

    public function test_self_test_profile_legs_are_not_recorded(): void
    {
        $this->postCdr($this->xml((string) Str::uuid(), ['sofia_profile_name' => 'selftest']))->assertOk();

        $this->assertSame(0, Cdr::query()->count());
    }
}
