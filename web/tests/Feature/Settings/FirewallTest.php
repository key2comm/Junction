<?php

namespace Tests\Feature\Settings;

use App\Jobs\ApplyFirewallPolicy;
use App\Jobs\ConfirmFirewallPolicy;
use App\Jobs\RefreshFirewallStatus;
use App\Jobs\UnbanAddress;
use App\Models\Account;
use App\Models\AccountIp;
use App\Models\FirewallEntry;
use App\Models\User;
use App\Services\FirewallPolicy;
use App\Services\FirewallSync;
use App\Support\Dns;
use App\Support\IpNet;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FirewallTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private const OFFICE = '198.51.100.7';

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
    }

    /** Requests from the office address, with a recent password confirmation. */
    private function asAdmin(string $ip = self::OFFICE): static
    {
        return $this->actingAs($this->admin)->withSession(['auth.password_confirmed_at' => time()])->withServerVariables(['REMOTE_ADDR' => $ip]);
    }

    private function add(string $list, string $value, ?string $reason = 'test', array $extra = [])
    {
        return $this->asAdmin()->post(route('firewall.entries.store'), ['list' => $list, 'value' => $value, 'reason' => $reason, ...$extra]);
    }

    public function test_ip_helpers(): void
    {
        $this->assertSame('10.1.2.0/24', IpNet::normalize('10.1.2.3/24'));
        $this->assertSame('10.1.2.3', IpNet::normalize(' 10.1.2.3/32 '));
        $this->assertSame('2001:db8::/32', IpNet::normalize('2001:DB8:1::5/32'));
        $this->assertNull(IpNet::normalize('10.1.2.3/33'));
        $this->assertNull(IpNet::normalize('office.example.com'));
        $this->assertTrue(IpNet::tooBroad('10.0.0.0/7'));
        $this->assertFalse(IpNet::tooBroad('10.0.0.0/8'));
        $this->assertTrue(IpNet::overlaps('10.1.2.0/24', '10.1.2.9'));
        $this->assertFalse(IpNet::overlaps('10.1.2.0/24', '10.1.3.9'));
        $this->assertTrue(IpNet::isHostname('office.example.com'));
        $this->assertFalse(IpNet::isHostname('not a host'));
    }

    public function test_page_shows_lists_bans_and_your_address(): void
    {
        $this->asAdmin()->get(route('firewall.index'))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('settings/Firewall')->where('you', ['ip' => self::OFFICE, 'trusted' => false])->where('status', null));

        Cache::put(FirewallPolicy::STATUS, ['pending' => false, 'utc_offset' => '+0000', 'ssh_clients' => [self::OFFICE],
            'bans' => [['jail' => 'freeswitch', 'ip' => '23.94.174.242', 'banned_at' => '2026-11-08 03:31:21', 'seconds' => 3600, 'until' => '2026-11-08 04:31:21']],
            'checked_at' => now()->toIso8601String()]);
        $this->add('allow', self::OFFICE, 'Office')->assertSessionHasNoErrors();
        $this->asAdmin()->get(route('firewall.index'))->assertInertia(fn (Assert $p) => $p
            ->where('you.trusted', true)->has('allow', 1)->where('allow.0.value', self::OFFICE)
            ->where('status.ssh_clients.0', ['ip' => self::OFFICE, 'trusted' => true])
            ->where('status.bans.0.ip', '23.94.174.242')->where('status.bans.0.until', '2026-11-08T04:31:21+00:00'));
    }

    public function test_entries_are_validated(): void
    {
        Dns::fake(['office.example.com' => ['203.0.113.5', '2001:db8::5']]);

        $this->add('allow', 'not an address')->assertSessionHasErrors('value');
        $this->add('allow', '10.0.0.0/7')->assertSessionHasErrors('value');
        $this->add('allow', 'nowhere.example.com')->assertSessionHasErrors('value');
        $this->add('block', '192.0.2.1', '')->assertSessionHasErrors('reason');

        $this->add('allow', 'Office.Example.com', null)->assertSessionHasNoErrors();
        $this->assertSame(['203.0.113.5', '2001:db8::5'], FirewallEntry::query()->sole()->resolved);
        $this->add('allow', 'office.example.com')->assertSessionHasErrors('value'); // already there
        Queue::assertPushed(ApplyFirewallPolicy::class);

        // The two lists can't overlap.
        $this->add('block', '203.0.113.0/24')->assertSessionHasErrors('value');
        // Not your own address, nor a customer's or carrier's.
        $this->add('block', self::OFFICE)->assertSessionHasErrors('value');
        AccountIp::factory()->create(['account_id' => Account::factory()->create(['is_customer' => true, 'is_vendor' => false]), 'ip' => '192.0.2.10']);
        $this->add('block', '192.0.2.0/24')->assertSessionHasErrors('value');

        $this->add('block', '192.0.3.9', 'SIP scanning', ['duration' => '1d'])->assertSessionHasNoErrors();
        $ban = FirewallEntry::query()->where('list', 'block')->sole();
        $this->assertSame('SIP scanning', $ban->reason);
        $this->assertTrue($ban->expires_at->between(now()->addHours(23), now()->addHours(25)));
        $this->assertSame($this->admin->id, $ban->user_id);
    }

    public function test_carrier_sip_trunks_are_whitelisted_on_every_port(): void
    {
        Dns::fake(['sip.carrier.example' => ['203.0.113.30']]);
        $trunk = \App\Models\Trunk::factory()->create();
        \App\Models\Gateway::factory()->create(['trunk_id' => $trunk->id, 'host' => 'sip.carrier.example']);
        \App\Models\Gateway::factory()->create(['trunk_id' => $trunk->id, 'host' => '198.51.100.40']);
        \App\Models\Gateway::factory()->create(['trunk_id' => $trunk->id, 'host' => '198.51.100.41', 'enabled' => false]);
        $this->add('allow', '192.0.2.5', 'Office');

        Process::fake(['*' => Process::result('3 trusted')]);
        app(FirewallSync::class)->applyPolicy();
        Process::assertRan(fn ($p) => $p->command[3] === 'policy' && str_contains($p->input, "trust 198.51.100.40
")
            && str_contains($p->input, "trust 203.0.113.30
") && ! str_contains($p->input, '198.51.100.41'));
        $this->assertTrue(FirewallPolicy::isTrusted('203.0.113.30'));
        $this->assertTrue(FirewallPolicy::isTrusted('192.0.2.5'));

        // A failed lookup keeps the carrier's last known address.
        Dns::fake([]);
        app(FirewallSync::class)->applyPolicy();
        $this->assertTrue(FirewallPolicy::isTrusted('203.0.113.30'));

        // Removing the SIP trunk takes it off.
        \App\Models\Gateway::query()->where('host', '198.51.100.40')->delete();
        app(FirewallSync::class)->applyPolicy();
        $this->assertFalse(FirewallPolicy::isTrusted('198.51.100.40'));
    }

    public function test_helper_input_has_lists_and_lockdown(): void
    {
        Dns::fake(['office.example.com' => ['203.0.113.5']]);
        $this->add('allow', 'office.example.com', null);
        $this->add('allow', '198.51.100.0/24', null);
        $this->add('block', '192.0.3.9', 'scan');
        FirewallEntry::create(['list' => 'block', 'value' => '192.0.3.10', 'reason' => 'old', 'expires_at' => now()->subMinute()]);
        Settings::put([FirewallPolicy::LOCKDOWN => ['ssh' => true, 'web' => true, 'pbx' => false]]);

        $this->assertSame("trust 203.0.113.5\ntrust 198.51.100.0/24\nblock 192.0.3.9\nlock ssh\n", FirewallPolicy::helperInput());

        Process::fake(['*' => Process::result('2 trusted, 1 blocked, locked: ssh')]);
        $this->assertSame(['policy' => '2 trusted, 1 blocked, locked: ssh'], app(FirewallSync::class)->applyPolicy());
        app(FirewallSync::class)->applyPolicy(180);
        Process::assertRan(fn ($p) => $p->command === ['sudo', '-n', config('softswitch.firewall.helper'), 'policy', '--confirm-within', '180']
            && str_contains($p->input, 'lock ssh'));
    }

    public function test_locking_ssh_needs_a_whitelist_and_confirmation(): void
    {
        $this->asAdmin()->put(route('firewall.lockdown'), ['ssh' => 1, 'web' => 0, 'pbx' => 0])->assertSessionHasErrors('lockdown');

        $this->add('allow', self::OFFICE, 'Office');
        // Someone is on SSH from an address that isn't whitelisted: refused.
        Cache::put(FirewallPolicy::STATUS, ['ssh_clients' => ['192.0.2.50'], 'bans' => []]);
        $this->asAdmin()->put(route('firewall.lockdown'), ['ssh' => 1, 'web' => 0, 'pbx' => 0])->assertSessionHasErrors('lockdown');
        Cache::put(FirewallPolicy::STATUS, ['ssh_clients' => [self::OFFICE, '192.0.2.50'], 'bans' => []]);

        $this->asAdmin()->put(route('firewall.lockdown'), ['ssh' => 1, 'web' => 0, 'pbx' => 0])->assertSessionHasNoErrors();
        $this->assertTrue(FirewallPolicy::lockdown()['ssh']);
        $this->assertSame('Lock down SSH', FirewallPolicy::pending()['what']);
        Queue::assertPushed(ApplyFirewallPolicy::class, fn ($j) => $j->confirm === true);

        // Other changes wait until it's confirmed.
        $this->add('allow', '203.0.113.9')->assertSessionHasErrors('value');

        $this->asAdmin()->post(route('firewall.confirm'))->assertSessionHasNoErrors();
        $this->assertNull(FirewallPolicy::pending());
        $this->assertTrue(FirewallPolicy::lockdown()['ssh']);
        Queue::assertPushed(ConfirmFirewallPolicy::class);
    }

    public function test_an_unconfirmed_change_falls_back(): void
    {
        $this->add('allow', self::OFFICE, 'Office');
        $this->asAdmin()->put(route('firewall.lockdown'), ['ssh' => 1, 'web' => 0, 'pbx' => 1])->assertSessionHasNoErrors();

        $this->travel(FirewallPolicy::CONFIRM_SECONDS + 5)->seconds();
        $this->assertNull(FirewallPolicy::pending());
        $this->assertFalse(FirewallPolicy::lockdown()['ssh']); // past the deadline: not in force
        $this->asAdmin()->post(route('firewall.confirm'))->assertSessionHasErrors('confirm');

        $this->travel(FirewallPolicy::GRACE_SECONDS)->seconds();
        $this->assertTrue(FirewallPolicy::finalizeExpired());
        $this->assertEquals(['ssh' => false, 'web' => false, 'pbx' => false], Settings::get(FirewallPolicy::LOCKDOWN));
        $this->assertNull(Settings::get(FirewallPolicy::PENDING));
        Queue::assertPushed(ApplyFirewallPolicy::class, fn ($j) => $j->rollbackFirst === true);
        $this->assertStringContainsString("wasn't confirmed in time", Cache::get(FirewallPolicy::ERROR));
    }

    public function test_removing_a_whitelist_entry_while_ssh_is_locked_needs_confirmation(): void
    {
        $this->add('allow', self::OFFICE, 'Office');
        $this->add('allow', '203.0.113.9', 'NOC');
        Settings::put([FirewallPolicy::LOCKDOWN => ['ssh' => true, 'web' => false, 'pbx' => false]]);

        $noc = FirewallEntry::query()->where('value', '203.0.113.9')->sole();
        $this->asAdmin()->delete(route('firewall.entries.destroy', $noc))->assertSessionHasNoErrors();
        $this->assertNull($noc->fresh());
        $pending = FirewallPolicy::pending();
        $this->assertSame('Remove 203.0.113.9 from the whitelist', $pending['what']);
        $this->assertFalse($pending['fallback']['ssh']); // not confirmed -> SSH lockdown off
    }

    public function test_admin_login_lockdown(): void
    {
        // Not from an address that isn't whitelisted.
        $this->add('allow', '203.0.113.9', 'NOC');
        $this->asAdmin()->put(route('firewall.lockdown'), ['ssh' => 0, 'web' => 1, 'pbx' => 0])->assertSessionHasErrors('lockdown');

        $this->add('allow', self::OFFICE, 'Office');
        $this->asAdmin()->put(route('firewall.lockdown'), ['ssh' => 0, 'web' => 1, 'pbx' => 0])->assertSessionHasNoErrors();
        Queue::assertNotPushed(ApplyFirewallPolicy::class, fn ($j) => $j->confirm); // app-level only
        $this->assertTrue(FirewallPolicy::lockdown()['web']);

        // In force straight away (that's the test the admin confirms).
        $this->asAdmin()->get(route('dashboard'))->assertOk();
        $this->actingAs($this->admin)->withServerVariables(['REMOTE_ADDR' => '192.0.2.200'])->get(route('dashboard'))
            ->assertRedirect(route('login'))->assertSessionHas('status');
        $this->assertGuest();

        // You can't remove the entry that lets you in.
        $this->asAdmin()->post(route('firewall.confirm'));
        $office = FirewallEntry::query()->where('value', self::OFFICE)->sole();
        $this->asAdmin()->delete(route('firewall.entries.destroy', $office))->assertSessionHasErrors('value');

        // Turning it off needs no confirmation.
        $this->asAdmin()->put(route('firewall.lockdown'), ['ssh' => 0, 'web' => 0, 'pbx' => 0])->assertSessionHasNoErrors();
        $this->assertNull(FirewallPolicy::pending());
        $this->assertFalse(FirewallPolicy::lockdown()['web']);
    }

    public function test_bans_can_be_lifted_or_blacklisted(): void
    {
        $this->asAdmin()->post(route('firewall.unban'), ['ip' => 'nope'])->assertSessionHasErrors('ip');
        $this->asAdmin()->post(route('firewall.unban'), ['ip' => '23.94.174.242'])->assertSessionHasNoErrors();
        Queue::assertPushed(UnbanAddress::class, fn ($j) => $j->ip === '23.94.174.242');

        $this->asAdmin()->post(route('firewall.bans.blacklist'), ['ip' => '54.38.94.109', 'jail' => 'freeswitch'])->assertSessionHasNoErrors();
        $this->assertSame('Banned by fail2ban (SIP login failures)', FirewallEntry::query()->where('value', '54.38.94.109')->sole()->reason);
    }

    public function test_status_job_reads_bans_and_drops_expired_entries(): void
    {
        FirewallEntry::create(['list' => 'block', 'value' => '192.0.3.10', 'reason' => 'old', 'expires_at' => now()->subMinute()]);
        Process::fake(['*' => Process::result(json_encode(['pending' => false, 'bans' => [], 'ssh_clients' => ['198.51.100.7'], 'utc_offset' => '+0000']))]);

        (new RefreshFirewallStatus)->handle(app(FirewallSync::class));
        $this->assertSame(0, FirewallEntry::query()->count());
        Queue::assertPushed(ApplyFirewallPolicy::class);
        $this->assertSame(['198.51.100.7'], Cache::get(FirewallPolicy::STATUS)['ssh_clients']);
    }

    public function test_unlock_command(): void
    {
        Settings::put([FirewallPolicy::LOCKDOWN => ['ssh' => true, 'web' => true, 'pbx' => false]]);
        Process::fake(['*' => Process::result('ok')]);
        $this->artisan('softswitch:firewall', ['action' => 'unlock', '--service' => ['web']])->assertSuccessful();
        $this->assertSame(['ssh' => true, 'web' => false, 'pbx' => false], FirewallPolicy::lockdown());
        Process::assertRan(fn ($p) => in_array('rollback', $p->command, true));
    }
}
