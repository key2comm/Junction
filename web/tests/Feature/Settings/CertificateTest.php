<?php

namespace Tests\Feature\Settings;

use App\Jobs\ManageCertificate;
use App\Models\User;
use App\Services\CertificateManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use Tests\TestCase;

class CertificateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['softswitch.public_ip' => '203.0.113.5']);
    }

    private function confirmedUser(): array
    {
        return [User::factory()->create(), ['auth.password_confirmed_at' => time()]];
    }

    private function fakeDns(array $ips): void
    {
        $this->partialMock(CertificateManager::class, function (MockInterface $mock) use ($ips) {
            $mock->shouldReceive('resolve')->andReturn($ips);
        });
    }

    public function test_guests_cannot_see_the_page(): void
    {
        $this->get(route('certificate.edit'))->assertRedirect(route('login'));
    }

    public function test_page_shows_and_requests_a_status_check_when_unknown(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('certificate.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/Certificate')
                ->where('status', null)
                ->where('defaults.email', $user->email));

        Queue::assertPushed(ManageCertificate::class, fn ($job) => $job->action === 'status');
    }

    public function test_requesting_a_certificate_requires_password_confirmation(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('certificate.store'), ['domain' => 'switch.example.com', 'email' => 'ops@example.com'])
            ->assertRedirect(route('password.confirm'));

        Queue::assertNothingPushed();
    }

    public function test_certificate_request_is_queued_when_dns_points_here(): void
    {
        Queue::fake();
        $this->fakeDns(['203.0.113.5']);
        [$user, $session] = $this->confirmedUser();

        $this->actingAs($user)->withSession($session)
            ->post(route('certificate.store'), ['domain' => 'Switch.Example.com', 'email' => 'ops@example.com'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('certificate.edit'));

        Queue::assertPushed(ManageCertificate::class, fn ($job) => $job->action === 'issue'
            && $job->domain === 'switch.example.com' && $job->email === 'ops@example.com');
    }

    public function test_certificate_request_is_rejected_when_dns_points_elsewhere(): void
    {
        Queue::fake();
        $this->fakeDns(['198.51.100.7']);
        [$user, $session] = $this->confirmedUser();

        $this->actingAs($user)->withSession($session)
            ->post(route('certificate.store'), ['domain' => 'switch.example.com', 'email' => 'ops@example.com'])
            ->assertSessionHasErrors('domain');

        Queue::assertNothingPushed();
    }

    public function test_invalid_domains_are_rejected(): void
    {
        Queue::fake();
        [$user, $session] = $this->confirmedUser();

        foreach (['localhost', 'bad domain.com', 'x.com;rm -rf /', '-bad.example.com'] as $domain) {
            $this->actingAs($user)->withSession($session)
                ->post(route('certificate.store'), ['domain' => $domain, 'email' => 'ops@example.com'])
                ->assertSessionHasErrors('domain');
        }

        Queue::assertNothingPushed();
    }

    public function test_only_one_certificate_change_runs_at_a_time(): void
    {
        Queue::fake();
        $this->fakeDns(['203.0.113.5']);
        app(CertificateManager::class)->markTask('running', 'issue');
        [$user, $session] = $this->confirmedUser();

        $this->actingAs($user)->withSession($session)
            ->post(route('certificate.store'), ['domain' => 'switch.example.com', 'email' => 'ops@example.com'])
            ->assertSessionHasErrors('domain');

        Queue::assertNothingPushed();
    }

    public function test_job_runs_the_helper_and_caches_the_result(): void
    {
        Process::fake([
            '*' => Process::result('{"ok":true,"mode":"letsencrypt","domain":"switch.example.com","expires":"Feb  4 03:52:09 2027 GMT","subject":"CN=switch.example.com"}'),
        ]);

        (new ManageCertificate('issue', 'switch.example.com', 'ops@example.com'))->handle(app(CertificateManager::class));

        Process::assertRan(fn ($process) => $process->command === [
            'sudo', '-n', '/usr/local/sbin/softswitch-cert', 'issue', 'switch.example.com', 'ops@example.com',
        ]);
        $status = Cache::get(CertificateManager::STATUS_KEY);
        $this->assertSame('letsencrypt', $status['mode']);
        $this->assertSame('2027-02-04T03:52:09+00:00', $status['expires']);
        $this->assertSame('done', app(CertificateManager::class)->task()['state']);
    }

    public function test_job_records_helper_failures(): void
    {
        Process::fake([
            '*' => Process::result('{"ok":false,"error":"certbot failed: DNS problem"}', exitCode: 1),
        ]);

        (new ManageCertificate('issue', 'switch.example.com', 'ops@example.com'))->handle(app(CertificateManager::class));

        $task = app(CertificateManager::class)->task();
        $this->assertSame('failed', $task['state']);
        $this->assertStringContainsString('DNS problem', $task['message']);
    }
}
