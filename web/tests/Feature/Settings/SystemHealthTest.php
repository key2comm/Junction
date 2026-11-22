<?php

namespace Tests\Feature\Settings;

use App\Models\Alert;
use App\Models\ApiToken;
use App\Models\Backup;
use App\Models\User;
use App\Services\AlertEngine;
use App\Services\Freeswitch\EventSocket;
use App\Services\SystemHealth;
use App\Support\AdminRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

/** Settings → System health, the server alert rules and the Grafana metrics. */
class SystemHealthTest extends TestCase
{
    use RefreshDatabase;

    private const STATUS = "UP 0 years, 2 days, 3 hours, 4 minutes, 5 seconds, 6 milliseconds, 7 microseconds\n"
        ."FreeSWITCH (Version 1.10.12 git abc 2024-08-02 21:01:49Z 64bit) is ready\n"
        ."1234 session(s) since startup\n"
        ."8 session(s) - peak 40, last 5min 12 \n"
        ."2 session(s) per Sec out of max 30, peak 9, last 5min 3 \n"
        ."1000 session(s) max\n"
        ."min idle cpu 0.00/97.50\n";

    private const SOFIA = "                     Name\t   Type\t                                       Data\tState\n"
        ."=================================================================================================\n"
        ."              carrier\tprofile\t           sip:mod_sofia@10.0.0.1:5080\tRUNNING (0)\n"
        ."             internal\tprofile\t           sip:mod_sofia@10.0.0.1:5060\tRUNNING (0)\n"
        ."             selftest\tprofile\t         sip:mod_sofia@127.0.0.1:5090\tRUNNING (0)\n"
        ."         carrier::gw1\tgateway\t                  sip:gw1@192.0.2.1\tNOREG\n";

    private string $cdrDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cdrDir = sys_get_temp_dir().'/ss-xml-cdr-'.uniqid();
        mkdir($this->cdrDir);
        config(['softswitch.freeswitch.cdr_error_dir' => $this->cdrDir]);
        // No real TLS connection in tests.
        Cache::put(SystemHealth::CERTIFICATE, ['expires' => now()->addDays(60)->toIso8601String(), 'days' => 60, 'issuer' => "Let's Encrypt", 'error' => null, 'checked_at' => now()->toIso8601String()]);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->cdrDir.'/*') ?: []);
        @rmdir($this->cdrDir);
        parent::tearDown();
    }

    private function fakeFreeswitch(?string $status = self::STATUS, string $sofia = self::SOFIA): void
    {
        $this->app->instance(EventSocket::class, new class($status, $sofia) extends EventSocket
        {
            public function __construct(private ?string $status, private string $sofia) {}

            public function apiMany(array $commands, ?float $timeout = null): array
            {
                if ($this->status === null) {
                    throw new RuntimeException('Connection refused');
                }

                return [$this->status, $this->sofia];
            }
        });
    }

    /** Fresh readings, as the scheduler would take them. */
    private function readings(?string $status = self::STATUS, string $sofia = self::SOFIA): void
    {
        $this->fakeFreeswitch($status, $sofia);
        $health = app(SystemHealth::class);
        $health->sample();
        $health->check();
    }

    private function check(string $key): array
    {
        return collect(SystemHealth::checks())->firstWhere('key', $key) ?? $this->fail("no {$key} check");
    }

    public function test_freeswitch_output_is_read(): void
    {
        $s = SystemHealth::parseStatus(self::STATUS);
        $this->assertSame(2 * 86400 + 3 * 3600 + 4 * 60 + 5, $s['uptime']);
        $this->assertSame('1.10.12', $s['version']);
        $this->assertSame([8, 40, 1234, 2, 30, 1000], [$s['sessions'], $s['sessions_peak'], $s['sessions_total'], $s['sessions_per_second'], $s['max_sessions_per_second'], $s['max_sessions']]);
        $this->assertSame(['carrier' => 'RUNNING', 'internal' => 'RUNNING', 'selftest' => 'RUNNING'], SystemHealth::parseSofia(self::SOFIA));
    }

    public function test_a_healthy_server_raises_nothing(): void
    {
        $this->readings();
        foreach (['scheduler', 'freeswitch', 'queue', 'cdr', 'certificate', 'partitions'] as $key) {
            $this->assertSame('ok', $this->check($key)['state'], $key.': '.$this->check($key)['summary']);
        }
        app(AlertEngine::class)->run();
        $this->assertSame(0, Alert::query()->whereIn('type', AlertEngine::HEALTH_RULES)->count());
    }

    public function test_freeswitch_down_alerts_only_after_the_grace_period_and_resolves(): void
    {
        $this->readings(null);
        $this->assertSame('critical', $this->check('freeswitch')['state']);
        app(AlertEngine::class)->run();
        $this->assertSame(0, Alert::query()->where('type', 'freeswitch_down')->count(), 'a restart takes a few seconds');

        $fast = Cache::get(SystemHealth::FAST);
        $fast['freeswitch']['down_since'] = now()->subMinutes(3)->toIso8601String();
        Cache::put(SystemHealth::FAST, $fast);
        app(AlertEngine::class)->run();
        $this->assertSame(1, Alert::query()->where('type', 'freeswitch_down')->where('status', 'open')->count());

        $this->readings();
        app(AlertEngine::class)->run();
        $this->assertSame(0, Alert::query()->where('type', 'freeswitch_down')->where('status', 'open')->count());
    }

    public function test_a_sip_profile_that_is_not_running_alerts(): void
    {
        $this->readings(self::STATUS, str_replace("5060\tRUNNING (0)", "5060\tDOWN", self::SOFIA));
        app(AlertEngine::class)->run();
        $this->assertSame('freeswitch_down:profile:internal', Alert::query()->where('type', 'freeswitch_down')->value('key'));
    }

    public function test_stuck_queue_unsaved_call_records_and_a_failed_backup_alert(): void
    {
        config(['queue.default' => 'database']);
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null,
            'available_at' => now()->subMinutes(15)->getTimestamp(), 'created_at' => now()->subMinutes(15)->getTimestamp()]);
        file_put_contents($this->cdrDir.'/a_1.cdr.xml', '<cdr/>');
        touch($this->cdrDir.'/a_1.cdr.xml', time() - 1200);
        Backup::query()->create(['filename' => 'b.tar', 'path' => '/nonexistent/b.tar', 'status' => 'failed', 'trigger' => 'schedule',
            'started_at' => now()->subHour(), 'finished_at' => now()->subHour(), 'error' => 'disk full']);

        $this->readings();
        app(AlertEngine::class)->run();

        $this->assertEqualsCanonicalizing(['queue_stuck', 'cdr_not_saved', 'backup_missed'],
            Alert::query()->whereIn('type', AlertEngine::HEALTH_RULES)->pluck('type')->all());
        $this->assertStringContainsString('disk full', Alert::query()->where('type', 'backup_missed')->value('title'));
    }

    public function test_disk_and_certificate_thresholds_come_from_the_rules(): void
    {
        $this->readings();
        DB::table('alert_rules')->where('type', 'disk_space')->update(['params' => json_encode(['percent' => 0])]);
        DB::table('alert_rules')->where('type', 'certificate_expiring')->update(['params' => json_encode(['days' => 90])]);
        app(AlertEngine::class)->run();
        $this->assertEqualsCanonicalizing(['disk_space', 'certificate_expiring'], Alert::query()->whereIn('type', AlertEngine::HEALTH_RULES)->pluck('type')->all());
    }

    public function test_stale_readings_mean_the_scheduler_stopped_and_leave_alerts_alone(): void
    {
        $this->readings();
        DB::table('alert_rules')->where('type', 'disk_space')->update(['params' => json_encode(['percent' => 0])]);
        app(AlertEngine::class)->run();
        $this->assertSame(1, Alert::query()->where('type', 'disk_space')->where('status', 'open')->count());

        foreach ([SystemHealth::FAST, SystemHealth::SLOW] as $key) {
            Cache::put($key, ['at' => now()->subMinutes(10)->toIso8601String()] + Cache::get($key));
        }
        $this->assertSame('critical', $this->check('scheduler')['state']);
        $this->assertSame('unknown', $this->check('disk')['state']);
        app(AlertEngine::class)->run();
        $this->assertSame(1, Alert::query()->where('type', 'disk_space')->where('status', 'open')->count(), 'not resolved without a reading');
    }

    public function test_the_page_and_the_grafana_dashboard(): void
    {
        $this->readings();
        $this->actingAs(User::factory()->create())->get(route('system-health.index'))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('settings/SystemHealth')->has('checks')->where('fast.freeswitch.sessions', 8)->has('slow.database.months'));
        $this->actingAs(User::factory()->create())->get(route('system-health.grafana'))->assertOk()->assertDownload('junction-grafana-dashboard.json');
        $this->actingAs(User::factory()->create(['role' => AdminRole::ReadOnly->value]))->get(route('system-health.index'))->assertOk();

        $dashboard = json_decode(file_get_contents(resource_path('grafana/junction-dashboard.json')), true);
        $this->assertSame('Junction softswitch', $dashboard['title']);
    }

    public function test_metrics_for_prometheus_need_the_metrics_ability(): void
    {
        $this->readings();
        [, $plain] = ApiToken::issue('grafana', ['metrics:read']);
        $body = $this->withHeaders(['Authorization' => "Bearer {$plain}"])->get('/api/v1/metrics')
            ->assertOk()->assertHeader('Content-Type', 'text/plain; version=0.0.4; charset=utf-8')->getContent();
        foreach (['junction_active_calls 0', 'junction_freeswitch_up 1', 'junction_freeswitch_sessions 8', 'junction_freeswitch_sessions_total 1234',
            'junction_freeswitch_profile_up{profile="carrier"} 1', 'junction_memory_total_bytes', 'junction_disk_used_bytes', '# TYPE junction_freeswitch_sessions_total counter'] as $line) {
            $this->assertStringContainsString($line, $body);
        }
        // Each metric's lines together, declared once.
        $this->assertSame(1, substr_count($body, '# TYPE junction_open_alerts gauge'));

        [, $other] = ApiToken::issue('other', ['alerts:read']);
        $this->withHeaders(['Authorization' => "Bearer {$other}"])->get('/api/v1/metrics')->assertForbidden();
        $this->flushHeaders()->get('/api/v1/metrics')->assertUnauthorized();
    }
}
