<?php

namespace Tests\Feature\Routing;

use App\Jobs\HangupCall;
use App\Models\Account;
use App\Models\ApiToken;
use App\Models\Endpoint;
use App\Models\Gateway;
use App\Models\Trunk;
use App\Models\User;
use App\Services\Freeswitch\EventSocket;
use App\Services\LiveCalls;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Tests\TestCase;

class LiveCallsTest extends TestCase
{
    use RefreshDatabase;

    private const A = '5a008cff-dda1-4d5b-adea-f3121f2ca1c9';

    private const B = 'e7b11435-8dad-468e-a240-8faa85a0f058';

    private const RINGING = '11111111-2222-3333-4444-555555555555';

    private Account $customer;

    private Gateway $gateway;

    private Endpoint $device;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setTimestamp(1_794_036_700));
        $this->customer = Account::factory()->create(['code' => 'CUST']);
        $trunk = Trunk::factory()->create(['name' => 'Main']);
        $this->gateway = Gateway::factory()->for($trunk)->create(['name' => 'main-gw', 'label' => 'Primary']);
        $this->device = Endpoint::create(['kind' => 'device', 'mode' => 'receiving', 'account_id' => $this->customer->id, 'name' => 'fusion-tenant',
            'delivery' => 'sip', 'hosts' => [], 'codecs' => ['PCMU'], 'flow' => 'always']);
        DB::table('active_calls')->insert([
            ['uuid' => self::A, 'account_id' => $this->customer->id, 'reserved' => '1.2', 'max_duration' => 3600],
            ['uuid' => self::RINGING, 'account_id' => $this->customer->id, 'reserved' => '1.2', 'max_duration' => 3600],
        ]);
    }

    private const DEVICE = '22222222-3333-4444-5555-666666666666';

    private const DEVICE_B = '33333333-4444-5555-6666-777777777777';

    private function fakeSwitch(): EventSocket
    {
        $esl = Mockery::mock(EventSocket::class);
        $esl->shouldReceive('api')->with('show channels as json')->andReturn(json_encode(['row_count' => 7, 'rows' => [
            // A customer call on the carrier port, answered, via main-gw.
            ['uuid' => self::A, 'direction' => 'inbound', 'name' => 'sofia/carrier/15550001000@198.51.100.7', 'created_epoch' => '1794036600',
                'cid_num' => '15550001000', 'dest' => '442079460000', 'callstate' => 'ACTIVE', 'ip_addr' => '198.51.100.7'],
            ['uuid' => self::B, 'direction' => 'outbound', 'name' => 'sofia/carrier/777#442079460000', 'created_epoch' => '1794036601',
                'dest' => '777#442079460000', 'callstate' => 'ACTIVE'],
            // A customer call still routing.
            ['uuid' => self::RINGING, 'direction' => 'inbound', 'name' => 'sofia/carrier/15550002000@198.51.100.7', 'created_epoch' => '1794036690',
                'cid_num' => '15550002000', 'dest' => '33123', 'callstate' => 'RING_WAIT'],
            // A device on the PBX port (internal profile), its carrier leg ringing; the legs aren't paired by FreeSWITCH.
            ['uuid' => self::DEVICE, 'direction' => 'inbound', 'name' => 'sofia/internal/100@tenant.example', 'created_epoch' => '1794036695',
                'cid_num' => '100', 'dest' => '14165550100', 'callstate' => 'EARLY', 'ip_addr' => '192.0.2.10'],
            ['uuid' => self::DEVICE_B, 'direction' => 'outbound', 'name' => 'sofia/carrier/14165550100', 'created_epoch' => '1794036696',
                'dest' => '14165550100', 'callstate' => 'EARLY'],
            // Not customer calls: the self-test's own caller leg.
            ['uuid' => 'aaaaaaaa-0000-0000-0000-000000000000', 'direction' => 'inbound', 'name' => 'sofia/selftest/1@x', 'created_epoch' => '1794036600'],
            ['uuid' => 'bbbbbbbb-0000-0000-0000-000000000000', 'direction' => 'outbound', 'name' => 'sofia/selftest/442079460000', 'created_epoch' => '1794036600'],
        ]]));
        $esl->shouldReceive('apiMany')->once()->with([
            'uuid_dump '.self::A.' json', 'uuid_dump '.self::RINGING.' json', 'uuid_dump '.self::DEVICE.' json',
            'uuid_getvar '.self::B.' softswitch_parent_uuid', 'uuid_getvar '.self::B.' softswitch_gateway_id',
            'uuid_getvar '.self::DEVICE_B.' softswitch_parent_uuid', 'uuid_getvar '.self::DEVICE_B.' softswitch_gateway_id',
        ])->andReturn([
            json_encode(['Caller-Channel-Answered-Time' => '1794036640123456', 'variable_softswitch_direction' => 'carrier', 'variable_read_codec' => 'PCMU']),
            json_encode(['Caller-Channel-Answered-Time' => '0']),
            json_encode(['Caller-Channel-Answered-Time' => '0', 'variable_softswitch_account_id' => (string) $this->customer->id,
                'variable_softswitch_endpoint_id' => (string) $this->device->id, 'variable_softswitch_matched_by' => 'domain', 'Other-Leg-Unique-ID' => self::DEVICE_B]),
            self::A, (string) $this->gateway->id,
            '_undef_', (string) $this->gateway->id,
        ]);

        return $esl;
    }

    public function test_snapshot_lists_customer_calls_with_vendor_and_timing(): void
    {
        $snapshot = (new LiveCalls($this->fakeSwitch()))->refresh();

        $this->assertCount(3, $snapshot['calls']);
        [$call, $ringing, $device] = $snapshot['calls'];
        $this->assertSame(self::A, $call['uuid']);
        $this->assertSame('answered', $call['state']);
        $this->assertSame('CUST', $call['customer']['code']);
        $this->assertSame('Primary', $call['gateway']);
        $this->assertSame('Main', $call['trunk']['name']);
        $this->assertSame(60, $call['talking_seconds']);       // answered at ...640, now ...700
        $this->assertSame(3540, $call['remaining_seconds']);   // 3600 max - 60
        $this->assertSame('777#442079460000', $call['sent_as']);
        $this->assertSame(['carrier', 'PCMU', '198.51.100.7'], [$call['direction'], $call['codec'], $call['source']]);
        $this->assertSame('routing', $ringing['state']);
        $this->assertSame('CUST', $ringing['customer']['code']); // from its funds reservation
        $this->assertNull($ringing['gateway']);

        // The PBX-port call: customer, device and how it was recognised come from its own variables.
        $this->assertSame(self::DEVICE, $device['uuid']);
        $this->assertSame('ringing', $device['state']);
        $this->assertSame(['CUST', 'fusion-tenant', 'domain', 'carrier'], [$device['customer']['code'] ?? null, $device['endpoint']['name'] ?? null, $device['matched_by'], $device['direction']]);
        $this->assertSame('14165550100', $device['sent_as']);
        $this->assertSame($snapshot, LiveCalls::snapshot());
    }

    public function test_snapshots_refresh_only_while_watched(): void
    {
        $this->app->instance(EventSocket::class, $this->fakeSwitch());

        Cache::forget(LiveCalls::WATCHING);
        $this->artisan('schedule:test', ['--name' => 'softswitch:live-calls'])->assertSuccessful();
        $this->assertNull(LiveCalls::snapshot()['at']);

        $this->actingAs(User::factory()->create())->get(route('live.index'))->assertInertia(fn (Assert $p) => $p->component('live/Index'));
        $this->assertTrue(Cache::has(LiveCalls::WATCHING));
        $this->artisan('schedule:test', ['--name' => 'softswitch:live-calls'])->assertSuccessful();
        $this->assertCount(3, LiveCalls::snapshot()['calls']);
    }

    public function test_only_calls_in_the_snapshot_can_be_hung_up(): void
    {
        Queue::fake([HangupCall::class]);
        (new LiveCalls($this->fakeSwitch()))->refresh();
        $admin = User::factory()->create();

        $this->actingAs($admin)->post(route('live.hangup', 'aaaaaaaa-0000-0000-0000-000000000000'))->assertSessionHasErrors('uuid');
        $this->actingAs($admin)->post(route('live.hangup', 'not-a-uuid'))->assertSessionHasErrors('uuid');
        $this->actingAs($admin)->post(route('live.hangup', self::A))->assertRedirect(route('live.index'));
        Queue::assertPushed(HangupCall::class, fn ($job) => $job->uuid === self::A && $job->userId === $admin->id);
    }

    public function test_hangup_job_kills_the_call(): void
    {
        $esl = Mockery::mock(EventSocket::class);
        $esl->shouldReceive('api')->with('uuid_kill '.self::A.' NORMAL_CLEARING')->once()->andReturn('+OK');
        $esl->shouldReceive('api')->with('show channels as json')->andReturn('{"row_count":0}');

        (new HangupCall(self::A))->handle($esl, new LiveCalls($esl));
        $this->assertSame([], LiveCalls::snapshot()['calls']);
    }

    public function test_api_returns_the_snapshot(): void
    {
        (new LiveCalls($this->fakeSwitch()))->refresh();
        [, $plain] = ApiToken::issue('live', ['routing:read']);

        $this->withHeaders(['Authorization' => "Bearer {$plain}"])->getJson('/api/v1/live-calls')
            ->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('data.0.gateway', 'Primary');
        $this->assertTrue(Cache::has(LiveCalls::WATCHING));
    }
}
