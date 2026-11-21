<?php

namespace App\Services;

use App\Mail\BackupMail;
use App\Models\Backup;
use App\Support\BackupSettings;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\Filesystem;
use League\Flysystem\PhpseclibV3\SftpAdapter;
use League\Flysystem\PhpseclibV3\SftpConnectionProvider;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Settings → Backups: a nightly backup of the database, kept on the server
 * (private disk, backups/) and optionally emailed, uploaded by SFTP and to
 * S3-compatible storage. Called every minute by `softswitch:backup --if-due`.
 *
 * The file is a tar of database.dump (pg_dump custom format), VERSION and
 * README.txt (how to restore). With a backup password it also holds app-key.txt
 * (the app key, needed to read encrypted values such as SIP passwords) and the
 * whole tar is encrypted with openssl (AES-256, PBKDF2).
 */
class BackupRunner
{
    public const DIR = 'backups';

    public const FILE_PREFIX = 'softswitch-backup-';

    /** openssl settings, also written into README.txt and shown on the page. */
    public const CIPHER = '-aes-256-cbc -pbkdf2 -iter 200000';

    /** A backup still "running" after this long died (killed, server restarted). */
    public const STALE_HOURS = 6;

    /** Is a backup due (Run now, or the daily time has passed since the last one)? */
    public function due(?CarbonImmutable $now = null): ?string
    {
        if (Settings::get(BackupSettings::REQUESTED)) {
            return 'manual';
        }
        if (! BackupSettings::get('enabled')) {
            return null;
        }
        $now ??= CarbonImmutable::now();
        $tz = BackupSettings::get('timezone') ?: 'UTC';
        [$h, $m] = array_map('intval', explode(':', (string) BackupSettings::get('time')) + [1 => 0]);
        $slot = $now->setTimezone($tz)->setTime($h, $m);
        if ($slot->greaterThan($now)) {
            $slot = $slot->subDay();
        }

        return Backup::query()->where('started_at', '>=', $slot->utc())->exists() ? null : 'schedule';
    }

    public function run(string $trigger = 'manual'): Backup
    {
        Settings::put([BackupSettings::REQUESTED => null]);
        Backup::query()->where('status', 'running')->where('started_at', '<', now()->subHours(self::STALE_HOURS))
            ->update(['status' => 'failed', 'error' => 'Stopped before it finished (the server or the job was restarted).', 'finished_at' => now()]);

        $s = BackupSettings::all();
        $password = (string) ($s['password'] ?? '');
        $host = Str::slug((string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: gethostname())) ?: 'switch';
        $filename = self::FILE_PREFIX.$host.'-'.now()->utc()->format('Ymd-His').($password !== '' ? '.tar.enc' : '.tar');
        $backup = Backup::create(['filename' => $filename, 'path' => self::DIR."/{$filename}", 'encrypted' => $password !== '',
            'status' => 'running', 'trigger' => $trigger, 'started_at' => now()]);

        $disk = Storage::disk('local');
        $disk->makeDirectory(self::DIR);
        $work = $disk->path(self::DIR.'/tmp-'.$backup->id);
        try {
            $this->checkSpace($disk->path(self::DIR));
            File::ensureDirectoryExists($work, 0700);
            $this->dumpDatabase("{$work}/database.dump");
            file_put_contents("{$work}/VERSION", trim((string) @file_get_contents(base_path('VERSION'))) ?: 'unknown');
            file_put_contents("{$work}/README.txt", $this->readme($filename, $password !== ''));
            if ($password !== '') {
                file_put_contents("{$work}/app-key.txt", 'APP_KEY='.config('app.key')."\n");
            }
            $this->pack($work, $disk->path($backup->path), $password);
            $backup->forceFill(['status' => 'done', 'size' => $disk->size($backup->path), 'finished_at' => now()])->save();
        } catch (Throwable $e) {
            $disk->delete($backup->path);
            $backup->forceFill(['status' => 'failed', 'error' => mb_substr('Backup: '.$e->getMessage(), 0, 2000), 'finished_at' => now(), 'path' => null])->save();
        } finally {
            File::deleteDirectory($work);
        }

        $this->deliver($backup, $s);
        $this->prune($s);

        return $backup;
    }

    /** pg_dump of the app's database (the HA schema is the HA script's own, and not readable by the app). */
    protected function dumpDatabase(string $file): void
    {
        $db = config('database.connections.'.config('database.default'));
        if (($db['driver'] ?? '') !== 'pgsql') {
            throw new RuntimeException('backups need the PostgreSQL database.');
        }
        $this->exec(['pg_dump', '-h', (string) ($db['host'] ?? '127.0.0.1'), '-p', (string) ($db['port'] ?? 5432), '-U', (string) $db['username'],
            '-d', (string) $db['database'], '--format=custom', '--exclude-schema=softswitch_ha', '-f', $file], ['PGPASSWORD' => (string) $db['password']]);
    }

    private function pack(string $dir, string $out, string $password): void
    {
        $script = $password === ''
            ? 'tar -C "$SS_SRC" -cf "$SS_OUT" .'
            : 'set -o pipefail; tar -C "$SS_SRC" -cf - . | openssl enc '.self::CIPHER.' -salt -pass env:SS_PASS -out "$SS_OUT"';
        $this->exec(['bash', '-c', $script], ['SS_SRC' => $dir, 'SS_OUT' => $out, 'SS_PASS' => $password]);
        @chmod($out, 0640);
    }

    private function exec(array $command, array $env): void
    {
        $process = new Process($command, base_path(), $env, null, 3 * 3600); // a readable folder, whoever runs it
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException("{$command[0]} failed: ".trim(mb_substr($process->getErrorOutput() ?: $process->getOutput(), 0, 1000)));
        }
    }

    /** Don't fill the disk: keep at least 1 GB, plus room for one more backup like the last one. */
    private function checkSpace(string $dir): void
    {
        $free = @disk_free_space($dir);
        $last = (int) Backup::query()->where('status', 'done')->latest('id')->value('size');
        if ($free !== false && $free < 1024 ** 3 + 2 * $last) {
            throw new RuntimeException(sprintf('not enough free disk space (%.1f GB free). Keep fewer backups or free some space.', $free / 1024 ** 3));
        }
    }

    public function deliver(Backup $backup, ?array $s = null): void
    {
        $s ??= BackupSettings::all();
        $errors = $backup->error ? [$backup->error] : [];
        $ok = $backup->status === 'done';
        $emails = self::emails($s['email_to'] ?? null);

        if ($ok && $s['sftp_enabled']) {
            try {
                $sftp = self::sftp($s);
                $stream = Storage::disk('local')->readStream($backup->path);
                try {
                    $sftp->writeStream($backup->filename, $stream);
                } finally {
                    is_resource($stream) && fclose($stream);
                }
                $backup->sftp_at = now();
            } catch (Throwable $e) {
                $errors[] = 'SFTP: '.$e->getMessage();
            }
        }
        if ($ok && $s['s3_enabled']) {
            try {
                self::s3($s)->put(self::s3Key($s, $backup->filename), Storage::disk('local')->path($backup->path));
                $backup->s3_at = now();
            } catch (Throwable $e) {
                $errors[] = 'S3: '.$e->getMessage();
            }
        }
        // Email: the backup (or, when it failed, what went wrong).
        if ($emails !== []) {
            try {
                if (! MailSettings::fresh()) {
                    throw new RuntimeException('email (SMTP) is not set up under Settings → Email.');
                }
                $backup->error = $errors === [] ? null : implode(' ', $errors);
                Mail::to($emails)->send(new BackupMail($backup));
                $backup->emailed_at = $ok ? now() : null;
            } catch (Throwable $e) {
                $errors[] = 'Email: '.$e->getMessage();
            }
        }
        $backup->error = $errors === [] ? null : mb_substr(implode(' ', $errors), 0, 2000);
        $backup->save();
    }

    /** Local backups past retention, and remote ones past their own "keep" days (0 = never delete). */
    public function prune(?array $s = null): void
    {
        $s ??= BackupSettings::all();
        $keep = max(1, (int) $s['retention_days']);
        Backup::query()->where('started_at', '<', now()->subDays($keep))->get()->each->delete();

        $old = fn (int $days) => fn (string $name) => $days > 0 && ($at = self::fileTime($name)) && $at->lt(now()->subDays($days));
        try {
            if ($s['sftp_enabled'] && ($days = (int) $s['sftp_keep_days']) > 0) {
                $sftp = self::sftp($s);
                foreach ($sftp->listContents('')->toArray() as $item) {
                    $name = basename($item->path());
                    if ($item->isFile() && str_starts_with($name, self::FILE_PREFIX) && $old($days)($name)) {
                        $sftp->delete($item->path());
                    }
                }
            }
        } catch (Throwable $e) {
            report($e);
        }
        try {
            if ($s['s3_enabled'] && ($days = (int) $s['s3_keep_days']) > 0) {
                $s3 = self::s3($s);
                foreach ($s3->list(self::s3Key($s, self::FILE_PREFIX)) as $key) {
                    if ($old($days)(basename($key))) {
                        $s3->delete($key);
                    }
                }
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** When a backup was made, from its name (softswitch-backup-<host>-YYYYmmdd-HHMMSS.tar[.enc]). */
    public static function fileTime(string $name): ?CarbonImmutable
    {
        return preg_match('/-(\d{8}-\d{6})\.tar(\.enc)?$/', $name, $m) ? CarbonImmutable::createFromFormat('Ymd-His', $m[1], 'UTC') : null;
    }

    /** @return list<string> */
    public static function emails(?string $to): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', (string) $to) ?: [])));
    }

    public static function sftp(array $s): Filesystem
    {
        return new Filesystem(new SftpAdapter(new SftpConnectionProvider(
            host: (string) $s['sftp_host'],
            username: (string) $s['sftp_username'],
            password: $s['sftp_password'] ?: null,
            privateKey: $s['sftp_private_key'] ?: null,
            port: (int) ($s['sftp_port'] ?: 22),
            timeout: 30,
            maxTries: 2,
            hostFingerprint: $s['sftp_fingerprint'] ?: null,
        ), rtrim((string) ($s['sftp_path'] ?: '.'), '/') ?: '/'));
    }

    public static function s3(array $s): S3Storage
    {
        return new S3Storage(BackupSettings::s3Endpoint($s), BackupSettings::s3Region($s), (string) $s['s3_bucket'], (string) $s['s3_key'], (string) $s['s3_secret']);
    }

    public static function s3Key(array $s, string $name): string
    {
        $prefix = trim((string) ($s['s3_path'] ?? ''), '/');

        return $prefix === '' ? $name : "{$prefix}/{$name}";
    }

    /** How to restore, shown on the page and written into every backup. */
    public static function restoreSteps(string $file = 'BACKUP_FILE', bool $encrypted = false): string
    {
        $db = config('database.connections.'.config('database.default'));
        $role = $db['username'] ?? 'softswitch';
        $name = $db['database'] ?? 'softswitch';
        $unpack = $encrypted ? "openssl enc -d ".self::CIPHER." -in {$file} | tar -xf -   # asks for the backup password" : "tar -xf {$file}";

        return <<<TXT
        mkdir restore && cd restore
        {$unpack}
        sudo systemctl stop softswitch-queue softswitch-scheduler
        sudo runuser -u postgres -- pg_restore --clean --if-exists --no-owner --role={$role} -d {$name} database.dump
        sudo systemctl start softswitch-queue softswitch-scheduler
        TXT;
    }

    private function readme(string $file, bool $encrypted): string
    {
        $steps = self::restoreSteps($file, $encrypted);
        $key = $encrypted
            ? "app-key.txt holds this switch's app key. On a new server, put that APP_KEY line in /var/www/html/.env\n(then run: sudo -u softswitch php /var/www/html/artisan config:cache) so saved SIP and SMTP passwords can be read."
            : "This backup is not encrypted and has no app key. On a new server, saved SIP and SMTP passwords can only be\nread with the old server's APP_KEY (in /var/www/html/.env). Set a backup password in Settings → Backups to include it.";

        return "Softswitch database backup\n\nContents: database.dump (pg_dump custom format), VERSION (the app version it came from), README.txt.\n\n"
            ."Restore (on a switch installed with the same or a newer version, as root):\n\n{$steps}\n\n{$key}\n";
    }
}
