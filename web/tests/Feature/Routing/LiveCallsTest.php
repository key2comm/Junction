<?php

namespace Tests\Feature\Routing;

use App\Jobs\HangupCall;
use App\Models\Account;
use App\Models\ApiToken;
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

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setTimestamp(1_794_036_700));
        $this->customer = Account::factory()->create(['code' => 'CUST']);
        $trunk = Trunk::factory()->create(['name' => 'Main']);
        $this->gateway = Gateway::factory()->for($trunk)->create(['name' => 'main-gw']);
        DB::table('active_calls')->insert([
            ['uuid' => self::A, 'account_id' => $this->customer->id, 'reserved' => '1.2', 'max_duration' => 3600],
            ['uuid' => self::RINGING, 'account_id' => $this->customer->id, 'reserved' => '1.2', 'max_duration' => 3600],
        ]);
    }

    private function fakeSwitch(): EventSocket
    {
        $esl = Mockery::mock(EventSocket::class);
        $esl->shouldReceive('api')->with('show calls as json')->andReturn(json_encode(['row_count' => 4, 'rows' => [
            // A customer call, answered, via main-gw.
            ['uuid' => self::A, 'direction' => 'inbound', 'name' => 'sofia/carrier/15550001000@198.51.100.7', 'created_epoch' => '1794036600',
                'cid_num' => '15550001000', 'dest' => '442079460000', 'callstate' => 'ACTIVE', 'b_uuid' => self::B, 'b_dest' => '777#442079460000', 'b_callstate' => 'ACTIVE'],
            // A customer call still ringing.
            ['uuid' => self::RINGING, 'direction' => 'inbound', 'name' => 'sofia/carrier/15550002000@198.51.100.7', 'created_epoch' => '1794036690',
                'cid_num' => '15550002000', 'dest' => '33123', 'callstate' => 'EARLY', 'b_uuid' => '', 'b_callstate' => ''],
            // Not customer legs: the self-test profile and an outbound leg.
            ['uuid' => 'aaaaaaaa-0000-0000-0000-000000000000', 'direction' => 'inbound', 'name' => 'sofia/selftest/1@x', 'created_epoch' => '1794036600'],
            ['uuid' => 'bbbbbbbb-0000-0000-0000-000000000000', 'direction' => 'outbound', 'name' => 'sofia/carrier/442079460000', 'created_epoch' => '1794036600'],
        ]]));
        $esl->shouldReceive('apiMany')->once()->with([
            'uuid_dump '.self::A.' json', 'uuid_getvar '.self::B.' softswitch_gateway_id',
            'uptime', 'uptime',
        ])->andReturn([json_encode(['Caller-Channel-Answered-Time' => '1794036640123456']), (string) $this->gateway->id, '12345', '12345']);

        return $esl;
    }

    public function test_snapshot_lists_customer_calls_with_vendor_and_timing(): void
    {
        $snapshot = (new LiveCalls($this->fakeSwitch()))->refresh();

        $this->assertCount(2, $snapshot['calls']);
        [$call, $ringing] = $snapshot['calls'];
        $this->assertSame(self::A, $call['uuid']);
        $this->assertSame('answered', $call['state']);
        $this->assertSame('CUST', $call['customer']['code']);
        $this->assertSame('main-gw', $call['gateway']);
        $this->assertSame('Main', $call['trunk']['name']);
        $this->assertSame(60, $call['talking_seconds']);       // answered at ...640, now ...700
        $this->assertSame(3540, $call['remaining_seconds']);   // 3600 max - 60
        $this->assertSame('777#442079460000', $call['sent_as']);
        $this->assertSame('routing', $ringing['state']);
        $this->assertNull($ringing['gateway']);
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
        $this->assertCount(2, LiveCalls::snapshot()['calls']);
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
        $esl->shouldReceive('api')->with('show calls as json')->andReturn('{"row_count":0}');

        (new HangupCall(self::A))->handle($esl, new LiveCalls($esl));
        $this->assertSame([], LiveCalls::snapshot()['calls']);
    }

    public function test_api_returns_the_snapshot(): void
    {
        (new LiveCalls($this->fakeSwitch()))->refresh();
        [, $plain] = ApiToken::issue('live', ['routing:read']);

        $this->withHeaders(['Authorization' => "Bearer {$plain}"])->getJson('/api/v1/live-calls')
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.gateway', 'main-gw');
        $this->assertTrue(Cache::has(LiveCalls::WATCHING));
    }
}
