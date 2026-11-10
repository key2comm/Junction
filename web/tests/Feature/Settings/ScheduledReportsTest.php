<?php

namespace Tests\Feature\Settings;

use App\Jobs\RunReport;
use App\Mail\ScheduledReportMail;
use App\Models\Account;
use App\Models\Cdr;
use App\Models\ReportFile;
use App\Models\ReportSchedule;
use App\Models\User;
use App\Services\ReportRunner;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ScheduledReportsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->admin = User::factory()->create();
        $this->withSession(['auth.password_confirmed_at' => time()]);
    }

    private function form(array $over = []): array
    {
        return ['name' => 'Daily CDRs', 'enabled' => '1', 'frequency' => 'daily', 'timezone' => 'America/Toronto', 'retention_days' => 30, ...$over];
    }

    public function test_periods_follow_the_schedule_time_zone(): void
    {
        $now = CarbonImmutable::parse('2026-11-10 03:30:00', 'UTC'); // 22:30 on 9 Nov in Toronto
        $s = new ReportSchedule(['frequency' => 'daily', 'timezone' => 'America/Toronto']);
        $this->assertSame(['2026-11-08 05:00', '2026-11-09 05:00'], array_map(fn ($t) => $t->format('Y-m-d H:i'), $s->lastPeriod($now)));
        $s->frequency = 'weekly';
        $this->assertSame(['2026-11-02 05:00', '2026-11-09 05:00'], array_map(fn ($t) => $t->format('Y-m-d H:i'), $s->lastPeriod($now)));
        $s->frequency = 'monthly';
        $this->assertSame(['2026-10-01 04:00', '2026-11-01 04:00'], array_map(fn ($t) => $t->format('Y-m-d H:i'), $s->lastPeriod($now)));
    }

    public function test_schedules_are_validated_and_secrets_are_write_only(): void
    {
        $this->actingAs($this->admin)->get(route('reports.index'))->assertInertia(fn (Assert $p) => $p->component('settings/Reports')->has('schedules', 0));
        $this->actingAs($this->admin)->get(route('reports.create'))->assertInertia(fn (Assert $p) => $p->component('settings/ReportForm'));

        $this->actingAs($this->admin)->post(route('reports.store'), $this->form(['frequency' => 'hourly']))->assertSessionHasErrors('frequency');
        $this->actingAs($this->admin)->post(route('reports.store'), $this->form(['email_to' => 'noc@example.com, nope']))->assertSessionHasErrors('email_to');
        $this->actingAs($this->admin)->post(route('reports.store'), $this->form(['sftp_enabled' => '1', 'sftp_host' => 'sftp.example.com', 'sftp_port' => 22, 'sftp_username' => 'cdr']))
            ->assertSessionHasErrors('sftp_password');
        $this->actingAs($this->admin)->post(route('reports.store'), $this->form(['email_to' => 'noc@example.com; billing@example.com', 'sftp_enabled' => '1',
            'sftp_host' => 'sftp.example.com', 'sftp_port' => 2222, 'sftp_username' => 'cdr', 'sftp_password' => 's3cret', 'sftp_path' => '/in',
            'filters' => ['direction' => 'outgoing', 'customer' => '']]))->assertSessionHasNoErrors()->assertRedirect(route('reports.index'));

        $s = ReportSchedule::query()->sole();
        $this->assertSame(['noc@example.com', 'billing@example.com'], $s->emailAddresses());
        $this->assertSame(['direction' => 'outgoing'], $s->filters);
        $this->assertSame('s3cret', $s->sftp_password);
        $this->assertNotSame('s3cret', $s->getRawOriginal('sftp_password')); // encrypted
        $this->actingAs($this->admin)->get(route('reports.edit', $s))->assertInertia(fn (Assert $p) => $p->where('schedule.has_password', true)->missing('schedule.sftp_password'));

        // A blank password keeps the saved one.
        $this->actingAs($this->admin)->put(route('reports.update', $s), $this->form(['name' => 'Renamed', 'sftp_enabled' => '1', 'sftp_host' => 'sftp.example.com',
            'sftp_port' => 22, 'sftp_username' => 'cdr', 'sftp_password' => '']))->assertSessionHasNoErrors();
        $this->assertSame(['Renamed', 's3cret'], [$s->fresh()->name, $s->fresh()->sftp_password]);
    }

    public function test_a_report_is_made_once_per_period_saved_and_emailed(): void
    {
        Mail::fake();
        Settings::put(['mail.host' => 'smtp.example.com', 'mail.from_address' => 'switch@example.com']);
        $customer = Account::factory()->create(['code' => 'ACME']);
        $s = ReportSchedule::create(['name' => 'Daily CDRs', 'frequency' => 'daily', 'timezone' => 'UTC', 'retention_days' => 30, 'email_to' => 'noc@example.com',
            'filters' => ['customer' => $customer->id]]);
        $now = CarbonImmutable::parse('2026-11-10 01:00:00', 'UTC');
        Cdr::factory()->create(['customer_account_id' => $customer->id, 'started_at' => '2026-11-09 12:00:00', 'caller' => '=cmd']);
        Cdr::factory()->create(['customer_account_id' => $customer->id, 'started_at' => '2026-11-09 23:59:59']);
        Cdr::factory()->create(['customer_account_id' => $customer->id, 'started_at' => '2026-11-10 00:00:00']); // the next day
        Cdr::factory()->create(['started_at' => '2026-11-09 12:00:00']); // another customer

        $runner = app(ReportRunner::class);
        $this->assertSame(1, $runner->runDue($now));
        $this->assertSame(0, $runner->runDue($now)); // already made for that day

        $file = ReportFile::query()->sole();
        $this->assertSame(['daily-cdrs-2026-11-09.csv', 2], [$file->filename, $file->rows]);
        $csv = Storage::disk('local')->get($file->path);
        $this->assertStringStartsWith('started_at_utc,answered_at_utc', $csv);
        $this->assertStringContainsString("'=cmd", $csv); // spreadsheet formulas neutralised
        $this->assertNotNull($file->emailed_at);
        $this->assertNull($file->error);
        Mail::assertSent(ScheduledReportMail::class, fn ($m) => $m->hasTo('noc@example.com') && $m->file->is($file));

        // Downloads, and deletion with its file.
        $this->actingAs($this->admin)->get(route('reports.files.download', $file))->assertOk()->assertDownload('daily-cdrs-2026-11-09.csv');
        $this->actingAs($this->admin)->delete(route('reports.files.destroy', $file))->assertRedirect(route('reports.index'));
        $this->assertFalse(Storage::disk('local')->exists($file->path));
    }

    public function test_delivery_problems_are_recorded_and_old_files_pruned(): void
    {
        $s = ReportSchedule::create(['name' => 'Weekly', 'frequency' => 'weekly', 'timezone' => 'UTC', 'retention_days' => 7, 'email_to' => 'noc@example.com']);
        $file = app(ReportRunner::class)->run($s); // email isn't set up
        $this->assertStringContainsString('Email: email (SMTP) is not set up', $file->error);
        $this->assertSame($file->error, $s->fresh()->last_error);
        $this->assertTrue(Storage::disk('local')->exists($file->path)); // the file is kept anyway

        $this->travel(8)->days();
        $this->assertSame(1, app(ReportRunner::class)->prune());
        $this->assertFalse(Storage::disk('local')->exists($file->path));
        $this->assertSame(0, ReportFile::query()->count());
    }

    public function test_run_now_queues_and_only_admins_get_in(): void
    {
        Queue::fake();
        $s = ReportSchedule::create(['name' => 'Daily', 'frequency' => 'daily', 'timezone' => 'UTC']);
        $this->actingAs($this->admin)->post(route('reports.run', $s))->assertRedirect(route('reports.index'));
        Queue::assertPushed(RunReport::class, fn ($j) => $j->scheduleId === $s->id);

        $portal = User::factory()->create(['account_id' => Account::factory()->create(['is_customer' => true])->id]);
        $this->actingAs($portal)->post(route('reports.run', $s))->assertForbidden();
    }
}
