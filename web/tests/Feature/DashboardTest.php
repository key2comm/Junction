<?php

namespace Tests\Feature;

use App\Models\Cdr;
use App\Models\User;
use App\Services\SystemStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertOk();
    }

    public function test_the_dashboard_counts_calls_in_the_viewers_own_days_and_hours(): void
    {
        $this->travelTo('2026-11-10 15:30:00');
        // Toronto is UTC-5 in November: 03:00 UTC on the 10th is still the 9th there.
        $user = User::factory()->create(['timezone' => 'America/Toronto']);
        Cdr::factory()->create(['started_at' => '2026-11-10 15:10:00', 'answered_at' => '2026-11-10 15:10:05', 'billsec' => 120]);
        Cdr::factory()->create(['started_at' => '2026-11-10 13:00:00']);
        Cdr::factory()->create(['started_at' => '2026-11-10 03:00:00']);
        Cdr::factory()->create(['started_at' => '2026-10-20 12:00:00']); // last month, outside the week

        $this->actingAs($user)->get(route('dashboard'))->assertInertia(fn (Assert $p) => $p
            ->where('today', ['calls' => 2, 'answered' => 1, 'minutes' => 2])
            ->where('month.calls', 3)
            ->has('week', 7)
            ->where('week.6', ['day' => '2026-11-10', 'calls' => 2, 'answered' => 1])
            ->where('week.5', ['day' => '2026-11-09', 'calls' => 1, 'answered' => 0])
            ->where('week.0.day', '2026-11-04')
            ->has('hours', 24)
            ->where('hours.23', ['hour' => '2026-11-10 10', 'calls' => 1, 'answered' => 1])
            ->where('hours.21', ['hour' => '2026-11-10 08', 'calls' => 1, 'answered' => 0])
            ->where('hours.11', ['hour' => '2026-11-09 22', 'calls' => 1, 'answered' => 0])
            ->where('system', null));
    }

    public function test_server_stats_come_from_proc_and_cpu_is_averaged_between_samples(): void
    {
        $proc = sys_get_temp_dir().'/ss-proc-'.uniqid();
        File::ensureDirectoryExists($proc);
        $write = fn (int $busy, int $idle) => file_put_contents("{$proc}/stat", "cpu  {$busy} 0 0 {$idle} 0 0 0 0 0 0\ncpu0 1 0 0 1\ncpu1 1 0 0 1\n");
        file_put_contents("{$proc}/meminfo", "MemTotal:       4000000 kB\nMemFree:         100000 kB\nMemAvailable:   1000000 kB\nSwapTotal:            0 kB\nSwapFree:             0 kB\n");
        file_put_contents("{$proc}/loadavg", "0.50 0.25 0.10 1/200 1234\n");
        file_put_contents("{$proc}/uptime", "90061.5 1000.0\n");
        $stats = new SystemStats($proc, sys_get_temp_dir());

        $write(1000, 9000);
        $this->assertNull($stats->sample()['cpu']); // needs two readings
        $write(1250, 9750); // 250 busy of 1000 → 25%
        $second = $stats->sample();
        File::deleteDirectory($proc);

        $this->assertSame(25.0, $second['cpu']);
        $this->assertSame(2, $second['cores']);
        $this->assertSame([0.5, 0.25, 0.1], $second['load']);
        $this->assertSame(['total' => 4096000000, 'used' => 3072000000], $second['memory']);
        $this->assertSame(90061, $second['uptime']);
        $this->assertCount(1, $second['cpu_history']);

        // Static facts: hostname and IP from the install, OS from os-release.
        config(['softswitch.hostname' => 'switch.example.net', 'softswitch.public_ip' => '192.0.2.5']);
        File::ensureDirectoryExists($proc);
        file_put_contents("{$proc}/os-release", "NAME=\"Rocky Linux\"\nPRETTY_NAME=\"Rocky Linux 10.2 (Red Quartz)\"\n");
        $this->assertSame(['hostname' => 'switch.example.net', 'ip' => '192.0.2.5', 'os' => 'Rocky Linux 10.2 (Red Quartz)', 'version' => config('softswitch.version')],
            (new SystemStats($proc, sys_get_temp_dir(), $proc))->host());
        File::deleteDirectory($proc);

        $this->actingAs(User::factory()->create())->get(route('dashboard'))->assertInertia(fn (Assert $p) => $p
            ->where('system.cpu', 25)->where('system.cores', 2)->missing('system.raw_cpu'));
    }

    public function test_admins_see_the_release_in_the_footer_and_customers_do_not(): void
    {
        $this->assertSame(trim(file_get_contents(base_path('VERSION'))), config('softswitch.version'));
        $this->actingAs(User::factory()->create())->get(route('dashboard'))
            ->assertInertia(fn (Assert $p) => $p->where('version', config('softswitch.version')));
        $customer = User::factory()->create(['account_id' => \App\Models\Account::factory()->create()->id]);
        $this->actingAs($customer)->get('/portal')->assertInertia(fn (Assert $p) => $p->where('version', null));
    }
}
