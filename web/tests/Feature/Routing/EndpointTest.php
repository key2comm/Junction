<?php

namespace Tests\Feature\Routing;

use App\Models\Account;
use App\Models\Endpoint;
use App\Models\PbxDid;
use App\Models\PbxExtension;
use App\Models\User;
use App\Services\EndpointSync;
use App\Services\FirewallSync;
use App\Services\Freeswitch\PbxSync;
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
        $this->customer = Account::factory()->create(['is_customer' => true, 'is_vendor' => false, 'status' => 'active', 'code' => 'ACME']);
    }

    private function pbx(array $over = []): array
    {
        return ['kind' => 'pbx', 'name' => 'Hosted PBX', 'enabled' => true, 'hosts' => ['pbx.example.com'],
            'transport' => 'udp', 'port' => 5060, 'codecs' => ['PCMU', 'PCMA'], ...$over];
    }

    private function device(array $over = []): array
    {
        return ['kind' => 'device', 'account_id' => $this->customer->id, 'name' => 'Office', 'enabled' => true, 'mode' => 'sending', 'delivery' => 'sip', 'target' => 'hosts',
            'hosts' => ['office.example.com'], 'transport' => 'udp', 'port' => 5060, 'codecs' => ['PCMU'], 'forward_caller_id' => 'original', ...$over];
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
            ->assertInertia(fn (Assert $p) => $p->component('endpoints/Form')->where('defaults.kind', 'device')->has('codecs', 6)->has('ruleTypes', 3)
                ->where('defaults.sip_password', fn ($pw) => strlen($pw) === 20));
    }

    public function test_a_pbx_is_shared(): void
    {
        $this->save($this->pbx(['hosts' => []]))->assertSessionHasErrors('hosts');
        $this->save($this->pbx(['hosts' => ['a.example.com', 'b.example.com']]))->assertSessionHasErrors('hosts');
        $this->save($this->pbx(['hosts' => ['not a host']]))->assertSessionHasErrors('hosts.0');
        $this->save($this->pbx(['codecs' => ['NOPE']]))->assertSessionHasErrors('codecs');

        // Customer, caller ID and outbound limits don't apply to a shared PBX.
        $this->save($this->pbx(['account_id' => $this->customer->id, 'transport' => 'tls', 'port' => 5061, 'codecs' => ['G729', 'PCMU'],
            'caller_id_number' => '14165550000', 'max_inbound' => 50, 'max_outbound' => 5, 'rules' => [['type' => 'ip', 'value' => '203.0.113.9']]]))
            ->assertSessionHasNoErrors()->assertRedirect(route('endpoints.index'));
        $e = Endpoint::query()->sole();
        $this->assertSame([null, 'pbx', ['PCMU', 'G729'], null, 50, null, 0], [$e->account_id, $e->kind, $e->codecs, $e->caller_id_number, $e->max_inbound, $e->max_outbound, $e->rules()->count()]);
        $this->assertSame('pbx.example.com:5061 (TLS)', $e->summary());
        $this->save($this->pbx())->assertSessionHasErrors('name'); // PBX names are unique

        // DIDs of any customer can go to it.
        $other = Account::factory()->create(['is_customer' => true]);
        $this->assertTrue(app(\App\Services\Pbx::class)->endpoints($other)->contains('id', $e->id));
        $this->actingAs($this->admin)->get(route('endpoints.index'))->assertInertia(fn (Assert $p) => $p->where('endpoints.0.account', null)->where('endpoints.0.dids', 0));
    }

    public function test_device_sending_calls(): void
    {
        $this->save($this->device(['account_id' => null]))->assertSessionHasErrors('account_id');
        $this->save($this->device(['hosts' => ['a.example.com', 'b.example.com']]))->assertSessionHasErrors('hosts'); // one IP/FQDN
        $this->save($this->device(['delivery' => 'flow', 'flow' => 'busy']))->assertSessionHasErrors('forward_number');
        $this->save($this->device(['delivery' => 'flow', 'flow' => 'busy', 'forward_number' => '5551234']))->assertSessionHasErrors('forward_number');
        $this->save($this->device(['delivery' => 'flow', 'flow' => 'busy', 'forward_number' => '14165551234', 'forward_caller_id' => 'endpoint']))->assertSessionHasErrors('caller_id_number');
        $this->save($this->device(['target' => 'trunk', 'hosts' => [], 'sip_username' => 'acme.limits', 'sip_password' => 'Abcdefgh12345678', 'max_inbound' => 10, 'max_total' => 5]))->assertSessionHasErrors('max_total');

        $this->save($this->device(['delivery' => 'flow', 'flow' => 'no_answer', 'forward_number' => '1 416 555 1234', 'ring_seconds' => 20,
            'hosts' => ['a.pstn.twilio.com']]))->assertSessionHasNoErrors();
        $e = Endpoint::query()->sole();
        $this->assertSame([$this->customer->id, 'no_answer', '14165551234', 20, ['a.pstn.twilio.com']], [$e->account_id, $e->flow, $e->forward_number, $e->ring_seconds, $e->hosts]);

        $this->save($this->device(['delivery' => 'flow', 'flow' => 'always', 'hosts' => [], 'forward_number' => '14165551234']), $e)->assertSessionHasNoErrors();
        $this->assertSame([[], 'Forward always to 14165551234'], [$e->fresh()->hosts, $e->fresh()->summary()]);

        $this->save($this->device(['delivery' => 'flow', 'flow' => 'pbx', 'pbx_destination' => ['type' => 'number', 'target' => '1001']]), $e)->assertSessionHasErrors('pbx_destination.type');
        PbxExtension::create(['account_id' => $this->customer->id, 'extension' => '1001', 'sip_username' => 'ep-1001', 'sip_password' => 'x',
            'voicemail_enabled' => true, 'enabled' => true]);
        $this->save($this->device(['delivery' => 'flow', 'flow' => 'pbx', 'pbx_destination' => ['type' => 'voicemail', 'target' => '1001']]), $e)->assertSessionHasNoErrors();
        $this->assertSame(['type' => 'voicemail', 'target' => '1001'], $e->fresh()->pbx_destination);
    }

    public function test_a_device_can_be_a_sip_trunk(): void
    {
        $trunk = fn (array $over = []) => $this->device(['target' => 'trunk', 'hosts' => [], 'sip_username' => 'acme.office', 'sip_password' => 'Abcdefgh12345678', 'add_one' => true, ...$over]);
        $this->save($trunk(['sip_username' => '']))->assertSessionHasErrors('sip_username');
        $this->save($trunk(['sip_password' => 'short']))->assertSessionHasErrors('sip_password');
        $this->save($trunk(['sip_password' => 'has spaces in it ok']))->assertSessionHasErrors('sip_password');
        PbxExtension::create(['account_id' => $this->customer->id, 'extension' => '1001', 'sip_username' => 'taken.user', 'sip_password' => 'x', 'enabled' => true]);
        $this->save($trunk(['sip_username' => 'taken.user']))->assertSessionHasErrors('sip_username');

        $this->save($trunk(['hosts' => ['ignored.example.com']]))->assertSessionHasNoErrors();
        $e = Endpoint::query()->sole();
        $this->assertSame(['acme.office', 'Abcdefgh12345678', []], [$e->sip_username, $e->sip_password, $e->hosts]);
        $this->assertNotSame('Abcdefgh12345678', DB::table('endpoints')->value('sip_password')); // encrypted at rest
        $this->assertSame('SIP trunk acme.office', $e->summary());
        $this->actingAs($this->admin)->get(route('endpoints.edit', $e))->assertInertia(fn (Assert $p) => $p->where('endpoint.sip_password', 'Abcdefgh12345678'));

        // The directory entry the PBX registers with: authenticated calls run the device dialplan.
        $xml = app(PbxSync::class)->renderTrunk($e);
        $this->assertStringContainsString('<user id="acme.office">', $xml);
        $this->assertStringContainsString('<param name="password" value="Abcdefgh12345678"/>', $xml);
        $this->assertStringContainsString('<variable name="user_context" value="device"/>', $xml);
        $this->assertStringContainsString('<variable name="softswitch_device_id" value="'.$e->id.'"/>', $xml);

        $row = DB::selectOne('SELECT * FROM softswitch_device(?)', [$e->id]);
        $this->assertSame([$this->customer->id, true, true], [$row->account_id, $row->active, $row->add_one]);
        $this->assertSame('acme.office', DB::selectOne('SELECT sip_username FROM softswitch_endpoint(?)', [$e->id])->sip_username);

        // Back to IP/FQDN drops the login.
        $this->save($this->device(), $e)->assertSessionHasNoErrors();
        $this->assertNull($e->fresh()->sip_username);
        $this->assertNull(DB::selectOne('SELECT * FROM softswitch_device(?)', [$e->id]));
    }

    public function test_receiving_rules_identify_the_customer(): void
    {
        Dns::fake(['hosted.example.com' => ['198.51.100.5'], 'office.example.com' => ['203.0.113.40']]);
        $this->save($this->pbx(['hosts' => ['hosted.example.com']]))->assertSessionHasNoErrors();
        $pbx = Endpoint::query()->sole();

        $rules = [['type' => 'domain', 'value' => '@Tenant.Example.com'], ['type' => 'number', 'value' => '+14165551234'],
            ['type' => 'number', 'value' => '4165551234'], ['type' => 'ip', 'value' => 'office.example.com']];
        $recv = fn (array $over = []) => $this->device(['mode' => 'receiving', ...$over]);
        $this->save($recv(['rules' => []]))->assertSessionHasErrors('rules');
        $this->save($recv(['rules' => [['type' => 'domain', 'value' => 'not a domain']]]))->assertSessionHasErrors('rules.0.value');
        $this->save($recv(['rules' => [['type' => 'number', 'value' => '416-555']]]))->assertSessionHasErrors('rules.0.value');
        $this->save($recv(['rules' => [['type' => 'ip', 'value' => '10.0.0.0/8']]]))->assertSessionHasErrors('rules.0.value');
        $this->save($recv(['rules' => $rules, 'caller_id_number' => '14165550000', 'add_one' => true, 'max_inbound' => 9, 'max_outbound' => 4, 'max_total' => 6]))->assertSessionHasNoErrors();
        $device = Endpoint::query()->where('kind', 'device')->sole();
        // Receiving only: nothing to send calls to, and no DIDs can go to it.
        $this->assertSame(['receiving', [], null, 4, null], [$device->mode, $device->hosts, $device->max_inbound, $device->max_outbound, $device->max_total]);
        $this->assertFalse(app(\App\Services\Pbx::class)->endpoints($this->customer)->contains('id', $device->id));
        $this->assertSame(['domain|tenant.example.com', 'ip|office.example.com', 'number|+14165551234', 'number|4165551234'],
            $device->rules->map(fn ($r) => "{$r->type}|{$r->value}")->all());

        // Rules are unique switch-wide.
        $this->save($recv(['name' => 'Other', 'rules' => [['type' => 'domain', 'value' => 'tenant.example.com']]]))->assertSessionHasErrors('rules.0.value');
        // A separate sending device may use the same FQDN as the receiving one's IP rule.
        $this->save($this->device(['name' => 'Office out', 'hosts' => ['office.example.com'], 'add_one' => true]))->assertSessionHasNoErrors();
        $this->assertFalse(Endpoint::query()->where('name', 'Office out')->sole()->add_one);

        $this->assertSame(['endpoints' => 3, 'addresses' => 2], app(EndpointSync::class)->sync());
        $who = fn (string $ip, string $host = '', string $caller = '') => DB::selectOne('SELECT * FROM softswitch_identify_endpoint(?::inet, ?, ?)', [$ip, $host, $caller]);

        // From the shared PBX: the From domain or the caller number (as the PBX sends it).
        $hit = $who('198.51.100.5', 'tenant.example.com', '100');
        $this->assertSame([$device->id, $this->customer->id, 'domain', '14165550000', 4, null, true], [$hit->endpoint_id, $hit->account_id, $hit->matched, $hit->caller_id_number, $hit->max_outbound, $hit->max_total, $hit->add_one]);
        $this->assertSame('number', $who('198.51.100.5', 'other.example.com', '4165551234')->matched);
        $this->assertNull($who('198.51.100.5', 'other.example.com', '14165551234')); // a format nobody added
        // Only the device's own rules decide: the FROM domain / number match whichever server sends it...
        $this->assertSame('domain', $who('192.0.2.77', 'tenant.example.com')->matched);
        $this->assertSame('number', $who('192.0.2.77', '', '+14165551234')->matched);
        $this->assertNull($who('192.0.2.77', 'unknown.example.com', '100')); // ...and nothing else
        // ...and the device's own IP rule by source address.
        $this->assertSame('ip', $who('203.0.113.40')->matched);

        // The firewall lets the PBX and the device's IP rule in.
        $allow = (new FirewallSync(fn () => []))->allowlist();
        $this->assertContains('198.51.100.5', $allow);
        $this->assertContains('203.0.113.40', $allow);

        // Removing a rule stops it matching.
        $this->save($recv(['rules' => [['type' => 'number', 'value' => '4165551234']]]), $device)->assertSessionHasNoErrors();
        $this->assertNull($who('198.51.100.5', 'tenant.example.com', '100'));
        $this->assertSame('number', $who('198.51.100.5', '', '4165551234')->matched);
    }

    public function test_a_receiving_device_can_be_recognised_by_the_customers_dids(): void
    {
        $provider = Account::factory()->vendor()->create();
        $other = Account::factory()->create(['is_customer' => true]);
        PbxDid::create(['account_id' => $this->customer->id, 'provider_account_id' => $provider->id, 'number' => '14165550100', 'destination' => ['type' => 'hangup']]);
        PbxDid::create(['account_id' => $this->customer->id, 'provider_account_id' => $provider->id, 'number' => '14165550101', 'destination' => ['type' => 'hangup'], 'status' => 'deactivated']);
        PbxDid::create(['account_id' => $other->id, 'provider_account_id' => $provider->id, 'number' => '14165550200', 'destination' => ['type' => 'hangup']]);

        $recv = fn (array $over = []) => $this->device(['mode' => 'receiving', 'hosts' => [], ...$over]);
        $this->save($recv(['rules' => [], 'match_dids' => false]))->assertSessionHasErrors('rules');
        $this->save($recv(['rules' => [], 'match_dids' => true]))->assertSessionHasNoErrors(); // the DID option alone is enough
        $device = Endpoint::query()->sole();
        $this->assertTrue($device->match_dids);

        $who = fn (string $caller) => DB::selectOne('SELECT * FROM softswitch_identify_endpoint(?::inet, ?, ?)', ['192.0.2.10', 'shared.example.com', $caller]);
        foreach (['14165550100', '+14165550100', '4165550100'] as $caller) {
            $hit = $who($caller);
            $this->assertSame([$device->id, $this->customer->id, 'did'], [$hit?->endpoint_id, $hit?->account_id, $hit?->matched], $caller);
        }
        $this->assertNull($who('14165550101')); // deactivated
        $this->assertNull($who('14165550200')); // another customer's DID
        $this->assertNull($who('100'));

        // A sending device can't use it.
        $this->save($this->device(['match_dids' => true]), $device)->assertSessionHasNoErrors();
        $this->assertFalse($device->fresh()->match_dids);
        $this->assertNull($who('14165550100'));
    }

    public function test_dids_can_point_at_a_pbx_or_device(): void
    {
        $pbx = Endpoint::create([...$this->pbx(), 'account_id' => null]);
        $device = Endpoint::create(collect($this->device())->except(['target'])->all());
        $other = Account::factory()->create(['is_customer' => true]);
        $otherDevice = Endpoint::create(collect($this->device(['account_id' => $other->id]))->except(['target'])->all());

        $provider = Account::factory()->vendor()->create();
        $store = fn (string $number, string $target) => $this->actingAs($this->admin)->post(route('dids.store'), ['number' => $number, 'account_id' => $this->customer->id,
            'provider_account_id' => $provider->id, 'language' => 'en', 'status' => 'active', 'destination' => ['type' => 'endpoint', 'target' => $target]]);
        $store('14165550100', (string) $otherDevice->id)->assertSessionHasErrors('destination.type');
        $store('14165550100', (string) $device->id)->assertSessionHasNoErrors();
        $store('14165550101', (string) $pbx->id)->assertSessionHasNoErrors();

        // A device with DIDs can't become receiving-only; a receiving device can't get DIDs.
        $this->actingAs($this->admin)->put(route('endpoints.update', $device), $this->device(['mode' => 'receiving', 'rules' => [['type' => 'ip', 'value' => '203.0.113.50']]]))
            ->assertSessionHasErrors('mode');
        $receiver = Endpoint::create([...collect($this->device(['name' => 'Office in', 'hosts' => []]))->except(['target'])->all(), 'mode' => 'receiving']);
        $store('14165550102', (string) $receiver->id)->assertSessionHasErrors('destination.type');

        $this->actingAs($this->admin)->get(route('dids.index', ['endpoint' => $pbx->id]))->assertInertia(fn (Assert $p) => $p
            ->has('dids.data', 1)->where('dids.data.0.destination', 'PBX: Hosted PBX')->where('filters.endpoint.name', 'Hosted PBX'));
        $this->actingAs($this->admin)->get(route('dids.create', ['account' => $this->customer->id]))->assertInertia(fn (Assert $p) => $p
            ->where('targets.'.$this->customer->id.'.endpoints', [['id' => $device->id, 'label' => 'Device: Office'], ['id' => $pbx->id, 'label' => 'PBX: Hosted PBX']])); // own devices first

        // Can't delete it while DIDs still use it.
        $this->actingAs($this->admin)->delete(route('endpoints.destroy', $pbx), ['confirm' => 'Hosted PBX'])->assertSessionHasErrors('confirm');
        PbxDid::query()->delete();
        $this->actingAs($this->admin)->delete(route('endpoints.destroy', $pbx), ['confirm' => 'Hosted PBX'])->assertRedirect(route('endpoints.index'));
        $this->assertNull($pbx->fresh());

        $row = DB::selectOne('SELECT * FROM softswitch_endpoint(?)', [$device->id]);
        $this->assertSame(['office.example.com', 'PCMU', 'sip', true], [$row->hosts, $row->codecs, $row->delivery, $row->active]);
    }
}
