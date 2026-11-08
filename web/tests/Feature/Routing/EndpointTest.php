<?php

namespace Tests\Feature\Routing;

use App\Models\Account;
use App\Models\Endpoint;
use App\Models\PbxDid;
use App\Models\PbxExtension;
use App\Models\User;
use App\Services\EndpointSync;
use App\Services\FirewallSync;
use App\Support\Dns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EndpointTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Account $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
        $this->customer = Account::factory()->create(['is_customer' => true, 'is_vendor' => false, 'status' => 'active']);
    }

    private function pbx(array $over = []): array
    {
        return ['account_id' => $this->customer->id, 'kind' => 'pbx', 'name' => 'Head office', 'enabled' => true, 'hosts' => ['pbx.example.com'],
            'transport' => 'udp', 'port' => 5060, 'codecs' => ['PCMU', 'PCMA'], 'forward_caller_id' => 'original', 'outbound_enabled' => true, ...$over];
    }

    private function save(array $data, ?Endpoint $endpoint = null)
    {
        return $endpoint
            ? $this->actingAs($this->admin)->put(route('endpoints.update', $endpoint), $data)
            : $this->actingAs($this->admin)->post(route('endpoints.store'), $data);
    }

    public function test_pages_render(): void
    {
        $this->actingAs($this->admin)->get(route('endpoints.index'))->assertOk()->assertInertia(fn (Assert $p) => $p->component('endpoints/Index'));
        $this->actingAs($this->admin)->get(route('endpoints.create', ['account' => $this->customer->id]))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('endpoints/Form')->where('defaults.account_id', $this->customer->id)->has('codecs', 6));
    }

    public function test_a_pbx_is_validated_and_saved(): void
    {
        $this->save($this->pbx(['hosts' => []]))->assertSessionHasErrors('hosts');
        $this->save($this->pbx(['hosts' => ['a.example.com', 'b.example.com']]))->assertSessionHasErrors('hosts');
        $this->save($this->pbx(['hosts' => ['not a host']]))->assertSessionHasErrors('hosts.0');
        $this->save($this->pbx(['hosts' => ['10.0.0.0/24']]))->assertSessionHasErrors('hosts.0');
        $this->save($this->pbx(['codecs' => ['NOPE']]))->assertSessionHasErrors('codecs');
        $this->save($this->pbx(['max_inbound' => 10, 'max_total' => 5]))->assertSessionHasErrors('max_total');
        $this->save($this->pbx(['account_id' => Account::factory()->vendor()->create()->id]))->assertSessionHasErrors('account_id');

        $this->save($this->pbx(['hosts' => [' PBX.Example.com '], 'transport' => 'tls', 'port' => 5061, 'codecs' => ['G729', 'PCMU', 'OPUS'],
            'caller_id_number' => '+1 (416) 555-0000', 'max_inbound' => 5, 'max_outbound' => 5, 'max_total' => 8]))
            ->assertSessionHasNoErrors()->assertRedirect(route('endpoints.index'));
        $e = Endpoint::query()->sole();
        $this->assertSame(['pbx.example.com'], $e->hosts);
        $this->assertSame(['PCMU', 'G729', 'OPUS'], $e->codecs); // preference order kept
        $this->assertSame('+14165550000', $e->caller_id_number);
        $this->assertSame('sip', $e->delivery);
        $this->assertSame('pbx.example.com:5061 (TLS)', $e->summary());

        // Names are unique per customer.
        $this->save($this->pbx())->assertSessionHasErrors('name');
    }

    public function test_device_call_flows(): void
    {
        $device = fn (array $over) => $this->pbx(['kind' => 'device', 'name' => 'Twilio', 'delivery' => 'flow',
            'hosts' => ['a.pstn.twilio.com', 'b.pstn.twilio.com', 'c.pstn.twilio.com'], ...$over]);

        $this->save($device(['hosts' => ['a.example.com', 'b.example.com', 'c.example.com', 'd.example.com'], 'flow' => 'busy', 'forward_number' => '14165551234']))
            ->assertSessionHasErrors('hosts');
        $this->save($device(['flow' => 'busy']))->assertSessionHasErrors('forward_number');
        $this->save($device(['flow' => 'busy', 'forward_number' => '5551234']))->assertSessionHasErrors('forward_number');
        $this->save($device(['flow' => 'busy', 'forward_number' => '14165551234', 'forward_caller_id' => 'endpoint']))->assertSessionHasErrors('caller_id_number');

        $this->save($device(['flow' => 'no_answer', 'forward_number' => '1 416 555 1234', 'ring_seconds' => 20]))->assertSessionHasNoErrors();
        $e = Endpoint::query()->sole();
        $this->assertSame(['no_answer', '14165551234', 20, 3], [$e->flow, $e->forward_number, $e->ring_seconds, count($e->hosts)]);
        $this->assertTrue($e->ringsHosts());

        // Forward always: no addresses needed (and none kept).
        $this->save($device(['flow' => 'always', 'hosts' => [], 'forward_number' => '14165551234']), $e)->assertSessionHasNoErrors();
        $this->assertSame([], $e->fresh()->hosts);
        $this->assertSame('Forward always to 14165551234', $e->fresh()->summary());

        // PBX on this softswitch: one of the customer's own numbers.
        $this->save($device(['flow' => 'pbx', 'hosts' => [], 'pbx_destination' => ['type' => 'number', 'target' => '1001']]), $e)->assertSessionHasErrors('pbx_destination.type');
        PbxExtension::create(['account_id' => $this->customer->id, 'extension' => '1001', 'sip_username' => 'ep-1001', 'sip_password' => 'x',
            'voicemail_enabled' => true, 'enabled' => true]);
        $this->save($device(['flow' => 'pbx', 'hosts' => [], 'pbx_destination' => ['type' => 'voicemail', 'target' => '1001']]), $e)->assertSessionHasNoErrors();
        $this->assertSame(['type' => 'voicemail', 'target' => '1001'], $e->fresh()->pbx_destination);
        $this->assertNull($e->fresh()->forward_number);
    }

    public function test_dids_can_point_at_a_pbx_device(): void
    {
        $e = Endpoint::create($this->pbx(['codecs' => ['PCMU']]));
        $other = Endpoint::create($this->pbx(['account_id' => Account::factory()->create(['is_customer' => true])->id]));

        $store = fn (array $dest) => $this->actingAs($this->admin)->post(route('accounts.pbx.dids.store', $this->customer), ['number' => '14165550100', 'destination' => $dest]);
        $store(['type' => 'endpoint', 'target' => (string) $other->id])->assertSessionHasErrors('destination.type');
        $store(['type' => 'endpoint', 'target' => (string) $e->id])->assertSessionHasNoErrors();
        $this->assertSame(['type' => 'endpoint', 'target' => (string) $e->id], PbxDid::query()->sole()->destination);

        $this->actingAs($this->admin)->get(route('accounts.pbx.show', $this->customer))->assertInertia(fn (Assert $p) => $p
            ->where('endpoints.0', ['id' => $e->id, 'label' => 'PBX: Head office'])->where('dids.0.label', 'PBX: Head office'));

        // Can't delete it while a DID still uses it.
        $this->actingAs($this->admin)->delete(route('endpoints.destroy', $e), ['confirm' => 'Head office'])->assertSessionHasErrors('confirm');
        PbxDid::query()->delete();
        $this->actingAs($this->admin)->delete(route('endpoints.destroy', $e), ['confirm' => 'head office'])->assertSessionHasErrors('confirm');
        $this->actingAs($this->admin)->delete(route('endpoints.destroy', $e), ['confirm' => 'Head office'])->assertRedirect(route('endpoints.index'));
        $this->assertNull($e->fresh());
    }

    public function test_call_path_functions_and_firewall(): void
    {
        Dns::fake(['a.pstn.twilio.com' => ['203.0.113.21'], 'b.pstn.twilio.com' => ['203.0.113.22']]);
        $pbx = Endpoint::create($this->pbx(['hosts' => ['198.51.100.5'], 'caller_id_number' => '14165550000', 'max_outbound' => 4, 'max_total' => 6]));
        $twilio = Endpoint::create($this->pbx(['kind' => 'device', 'name' => 'Twilio', 'hosts' => ['a.pstn.twilio.com', 'b.pstn.twilio.com'],
            'codecs' => ['PCMU', 'G722'], 'outbound_enabled' => false, 'delivery' => 'flow', 'flow' => 'busy', 'forward_number' => '14165551234']));

        $this->assertSame(['endpoints' => 2, 'addresses' => 3], app(EndpointSync::class)->sync());

        $row = DB::selectOne('SELECT * FROM softswitch_endpoint(?)', [$twilio->id]);
        $this->assertSame(['a.pstn.twilio.com,b.pstn.twilio.com', 'PCMU,G722', 'flow', 'busy', '14165551234', true],
            [$row->hosts, $row->codecs, $row->delivery, $row->flow, $row->forward_number, $row->active]);

        $out = DB::selectOne("SELECT * FROM softswitch_identify_endpoint('198.51.100.5'::inet)");
        $this->assertSame([$pbx->id, $this->customer->id, '14165550000', 4, 6], [$out->endpoint_id, $out->account_id, $out->caller_id_number, $out->max_outbound, $out->max_total]);
        // Inbound-only devices don't place calls through us.
        $this->assertNull(DB::selectOne("SELECT * FROM softswitch_identify_endpoint('203.0.113.21'::inet)"));

        $allow = (new FirewallSync(fn () => []))->allowlist();
        $this->assertContains('198.51.100.5', $allow);
        $this->assertNotContains('203.0.113.21', $allow);

        // A failed lookup keeps the last known addresses.
        Dns::fake([]);
        app(EndpointSync::class)->sync();
        $this->assertSame(2, $twilio->addresses()->count());
    }
}
