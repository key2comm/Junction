<?php

namespace Tests\Feature\Settings;

use App\Models\Backup;
use App\Models\User;
use App\Services\BackupRunner;
use App\Support\AdminRole;
use App\Support\BackupSettings;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Settings → Backups. pg_dump itself is replaced by a stand-in file; tar and openssl run for real. */
class BackupTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->owner = User::factory()->create();
        $this->app->bind(BackupRunner::class, fn () => new class extends BackupRunner
        {
            protected function dumpDatabase(string $file): void
            {
                file_put_contents($file, 'PGDMP stand-in');
            }
        });
    }

    private function save(array $data)
    {
        return $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()])->put(route('backups.update'), [
            'enabled' => '1', 'time' => '02:30', 'timezone' => 'America/Toronto', 'retention_days' => 7, 'sftp_keep_days' => 30, 's3_keep_days' => 30,
            's3_provider' => 'aws', ...$data,
        ]);
    }

    public function test_settings_are_saved_and_secrets_never_sent_back(): void
    {
        $this->save(['password' => 'a long backup password', 's3_enabled' => '1', 's3_provider' => 'wasabi', 's3_region' => 'ca-central-1',
            's3_bucket' => 'switch-backups', 's3_key' => 'AKIDEXAMPLE', 's3_secret' => 'top-secret-key', 's3_path' => 'tor1'])->assertSessionHasNoErrors();

        $this->assertSame('top-secret-key', BackupSettings::get('s3_secret'));
        $this->assertNotSame('top-secret-key', json_decode((string) DB::table('app_settings')->where('key', 'backup.s3_secret')->value('value')));
        $this->actingAs($this->owner)->get(route('backups.edit'))->assertOk()->assertInertia(fn (Assert $p) => $p->component('settings/Backups')
            ->where('settings.has_s3_secret', true)->where('settings.has_password', true)->missing('settings.s3_secret')->missing('settings.password')
            ->where('settings.s3_bucket', 'switch-backups'));

        // Blank keeps the saved secret.
        $this->save(['s3_enabled' => '1', 's3_provider' => 'wasabi', 's3_bucket' => 'switch-backups', 's3_key' => 'AKIDEXAMPLE'])->assertSessionHasNoErrors();
        $this->assertSame('top-secret-key', BackupSettings::get('s3_secret'));
    }

    public function test_copies_sent_off_the_server_need_a_password_or_an_explicit_ok(): void
    {
        $this->save(['email_to' => 'noc@example.com'])->assertSessionHasErrors('password');
        $this->save(['email_to' => 'noc@example.com', 'unencrypted_ok' => '1'])->assertSessionHasNoErrors();
        $this->save(['s3_enabled' => '1', 's3_provider' => 'other', 's3_bucket' => 'b1x', 's3_key' => 'k', 'unencrypted_ok' => '1'])
            ->assertSessionHasErrors('s3_endpoint');
        $this->save(['s3_enabled' => '1', 's3_provider' => 'aws', 's3_bucket' => 'b1x', 's3_key' => 'k', 'unencrypted_ok' => '1'])
            ->assertSessionHasErrors('s3_secret');
        $this->save(['password' => 'short'])->assertSessionHasErrors('password');
    }

    public function test_only_owners_and_admins_manage_backups(): void
    {
        $ops = User::factory()->create(['role' => AdminRole::Operations->value]);
        $this->actingAs($ops)->get(route('backups.edit'))->assertRedirect(route('dashboard'));
        $this->actingAs(User::factory()->create(['role' => AdminRole::Admin->value]))->get(route('backups.edit'))->assertOk();
    }

    public function test_a_backup_is_due_after_the_daily_time_or_run_now(): void
    {
        BackupSettings::put(['time' => '02:00', 'timezone' => 'America/Toronto']);
        $runner = app(BackupRunner::class);
        $at = fn (string $t) => CarbonImmutable::parse($t, 'America/Toronto');

        $this->assertSame('schedule', $runner->due($at('2026-11-21 02:05')));
        Backup::create(['status' => 'done', 'trigger' => 'schedule', 'started_at' => $at('2026-11-21 02:01')->utc()]);
        $this->assertNull($runner->due($at('2026-11-21 23:00')));
        $this->assertSame('schedule', $runner->due($at('2026-11-22 02:00')));

        $this->actingAs($this->owner)->post(route('backups.run'))->assertRedirect(route('backups.edit'));
        $this->assertSame('manual', $runner->due($at('2026-11-21 23:00')));

        BackupSettings::put(['enabled' => false]);
        Settings::put([BackupSettings::REQUESTED => null]);
        $this->assertNull($runner->due($at('2026-11-22 03:00')));
    }

    public function test_hourly_backups_run_every_hour_at_the_chosen_minute(): void
    {
        BackupSettings::put(['frequency' => 'hourly', 'time' => '02:15', 'timezone' => 'America/Toronto']);
        $runner = app(BackupRunner::class);
        $at = fn (string $t) => CarbonImmutable::parse($t, 'America/Toronto');

        $this->assertSame('schedule', $runner->due($at('2026-11-21 14:20')));
        Backup::create(['status' => 'done', 'trigger' => 'schedule', 'started_at' => $at('2026-11-21 14:15')->utc()]);
        $this->assertNull($runner->due($at('2026-11-21 15:10')));      // not yet 15:15
        $this->assertSame('schedule', $runner->due($at('2026-11-21 15:15')));
        $this->assertTrue($runner->nextSlot($at('2026-11-21 14:20'))->eq($at('2026-11-21 15:15')));

        // Saved from the form; nightly stays the default.
        $this->save(['frequency' => 'weekly'])->assertSessionHasErrors('frequency');
        $this->save(['frequency' => 'daily'])->assertSessionHasNoErrors();
        $this->assertSame('daily', BackupSettings::get('frequency'));
        $this->save([])->assertSessionHasNoErrors(); // left out: unchanged
        $this->assertSame('daily', BackupSettings::get('frequency'));
    }

    public function test_hourly_backups_are_thinned_to_one_a_day_after_a_day(): void
    {
        $now = CarbonImmutable::parse('2026-11-25 12:00', 'UTC');
        $made = [
            'recent1' => $now->subHours(2),
            'recent2' => $now->subHours(23),
            'day1-early' => CarbonImmutable::parse('2026-11-23 01:00', 'UTC'),
            'day1-late' => CarbonImmutable::parse('2026-11-23 23:00', 'UTC'),
            'day1-mid' => CarbonImmutable::parse('2026-11-23 12:00', 'UTC'),
            'too-old' => $now->subDays(8),
        ];
        $delete = BackupRunner::expired($made, 7, true, $now);
        sort($delete);
        $this->assertSame(['day1-early', 'day1-mid', 'too-old'], $delete);
        // Nightly: only the age limit applies.
        $this->assertSame(['too-old'], BackupRunner::expired($made, 7, false, $now));
    }

    public function test_an_encrypted_backup_holds_the_dump_and_the_app_key(): void
    {
        BackupSettings::put(['password' => 'correct horse battery staple']);
        $backup = app(BackupRunner::class)->run();

        $this->assertSame('done', $backup->status, (string) $backup->error);
        $this->assertTrue($backup->encrypted);
        $this->assertStringEndsWith('.tar.enc', $backup->filename);
        $file = Storage::disk('local')->path($backup->path);
        $this->assertStringNotContainsString('PGDMP', (string) file_get_contents($file));

        $list = Process::fromShellCommandline('openssl enc -d '.BackupRunner::CIPHER.' -pass env:P -in "$F" | tar -tf -', null, ['P' => 'correct horse battery staple', 'F' => $file]);
        $list->mustRun();
        foreach (['database.dump', 'VERSION', 'README.txt', 'app-key.txt'] as $name) {
            $this->assertStringContainsString($name, $list->getOutput());
        }
        $this->assertFalse(is_dir(Storage::disk('local')->path('backups/tmp-'.$backup->id)));
    }

    public function test_an_unencrypted_backup_has_no_app_key_and_old_ones_are_pruned(): void
    {
        $old = Backup::create(['filename' => 'old.tar', 'path' => 'backups/old.tar', 'status' => 'done', 'started_at' => now()->subDays(8)]);
        Storage::disk('local')->put('backups/old.tar', 'x');

        $backup = app(BackupRunner::class)->run();
        $this->assertSame('done', $backup->status, (string) $backup->error);
        $list = Process::fromShellCommandline('tar -tf "$F"', null, ['F' => Storage::disk('local')->path($backup->path)]);
        $list->mustRun();
        $this->assertStringContainsString('database.dump', $list->getOutput());
        $this->assertStringNotContainsString('app-key.txt', $list->getOutput());

        $this->assertModelMissing($old);
        Storage::disk('local')->assertMissing('backups/old.tar');
    }

    public function test_uploads_to_s3_compatible_storage_signed_and_prunes_old_copies(): void
    {
        BackupSettings::put(['password' => 'correct horse battery staple', 's3_enabled' => true, 's3_provider' => 'wasabi', 's3_region' => 'ca-central-1',
            's3_bucket' => 'switch-backups', 's3_key' => 'AKIDEXAMPLE', 's3_secret' => 'secret', 's3_path' => 'tor1', 's3_keep_days' => 30]);
        $old = BackupRunner::FILE_PREFIX.'host-'.now()->subDays(40)->format('Ymd-His').'.tar.enc';
        $recent = BackupRunner::FILE_PREFIX.'host-'.now()->subDays(2)->format('Ymd-His').'.tar.enc';
        Http::fake([
            '*list-type=2*' => Http::response("<?xml version=\"1.0\"?><ListBucketResult><IsTruncated>false</IsTruncated><Contents><Key>tor1/{$old}</Key></Contents><Contents><Key>tor1/{$recent}</Key></Contents></ListBucketResult>"),
            '*' => Http::response(''),
        ]);

        $backup = app(BackupRunner::class)->run();
        $this->assertNotNull($backup->s3_at, (string) $backup->error);
        $this->assertNull($backup->error);

        Http::assertSent(function (HttpRequest $r) use ($backup) {
            return $r->method() === 'PUT'
                && $r->url() === "https://s3.ca-central-1.wasabisys.com/switch-backups/tor1/{$backup->filename}"
                && str_starts_with($r->header('Authorization')[0], 'AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/'.gmdate('Ymd').'/ca-central-1/s3/aws4_request, SignedHeaders=host;x-amz-content-sha256;x-amz-date, Signature=')
                && $r->header('x-amz-content-sha256')[0] === hash_file('sha256', Storage::disk('local')->path($backup->path));
        });
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), "/switch-backups/tor1/{$old}"));
        Http::assertNotSent(fn (HttpRequest $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), $recent));
    }

    public function test_a_failed_upload_is_shown_on_the_backup(): void
    {
        BackupSettings::put(['s3_enabled' => true, 's3_provider' => 'aws', 's3_bucket' => 'b-1', 's3_key' => 'k', 's3_secret' => 's']);
        Http::fake(['*' => Http::response('<Error><Code>AccessDenied</Code><Message>Access Denied</Message></Error>', 403)]);

        $backup = app(BackupRunner::class)->run();
        $this->assertSame('done', $backup->status);
        $this->assertNull($backup->s3_at);
        $this->assertStringContainsString('S3: PUT failed (HTTP 403, AccessDenied: Access Denied)', (string) $backup->error);
    }

    public function test_download_needs_a_recent_password_confirmation(): void
    {
        $backup = app(BackupRunner::class)->run();
        $this->actingAs($this->owner)->get(route('backups.download', $backup))->assertRedirect(route('password.confirm'));
        $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()])->get(route('backups.download', $backup))->assertOk()->assertDownload($backup->filename);
    }
}
