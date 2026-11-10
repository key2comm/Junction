<?php

namespace App\Services;

use App\Mail\ScheduledReportMail;
use App\Models\ReportFile;
use App\Models\ReportSchedule;
use App\Support\CdrFilters;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\Filesystem;
use League\Flysystem\PhpseclibV3\SftpAdapter;
use League\Flysystem\PhpseclibV3\SftpConnectionProvider;
use RuntimeException;
use Throwable;

/**
 * Settings → Scheduled reports: makes a schedule's call records CSV for one
 * period, keeps it on the server (private local disk, reports/<schedule>/),
 * then emails and/or uploads it by SFTP. Delivery problems are recorded on the
 * file (it stays available to download); runDue() is called hourly.
 */
class ReportRunner
{
    /** Bigger files are not attached: the email says where to download them. */
    public const MAX_ATTACHMENT = 10 * 1024 * 1024;

    /** Make the file for each enabled schedule's last ended period, once. */
    public function runDue(?CarbonImmutable $now = null): int
    {
        $made = 0;
        foreach (ReportSchedule::query()->where('enabled', true)->get() as $schedule) {
            [$start] = $schedule->lastPeriod($now);
            if (! $schedule->files()->where('period_start', $start)->exists()) {
                $this->run($schedule, $now);
                $made++;
            }
        }

        return $made;
    }

    /** Make (or remake) the file for the last ended period, then deliver it. */
    public function run(ReportSchedule $schedule, ?CarbonImmutable $now = null): ReportFile
    {
        [$start, $end] = $schedule->lastPeriod($now);
        $local = $start->setTimezone($schedule->timezone ?: 'UTC');
        $label = match ($schedule->frequency) {
            'weekly' => 'week-'.$local->format('Y-m-d'),
            'monthly' => $local->format('Y-m'),
            default => $local->format('Y-m-d'),
        };
        $filename = sprintf('%s-%s.csv', Str::slug($schedule->name) ?: 'report', $label);
        $path = "reports/{$schedule->id}/{$filename}";

        $disk = Storage::disk('local');
        $disk->makeDirectory("reports/{$schedule->id}");
        $out = fopen($disk->path($path), 'w');
        try {
            $rows = CdrCsv::write($out, new CdrFilters($start, $end, array_filter($schedule->filters ?? [], fn ($v) => $v !== null && $v !== '')));
        } finally {
            fclose($out);
        }

        $file = ReportFile::query()->updateOrCreate(
            ['report_schedule_id' => $schedule->id, 'period_start' => $start],
            ['period_end' => $end, 'path' => $path, 'filename' => $filename, 'size' => $disk->size($path), 'rows' => $rows,
                'emailed_at' => null, 'uploaded_at' => null, 'error' => null],
        );
        $this->deliver($schedule, $file);
        $schedule->forceFill(['last_run_at' => now(), 'last_error' => $file->error])->save();

        return $file;
    }

    public function deliver(ReportSchedule $schedule, ReportFile $file): void
    {
        $errors = [];
        if ($schedule->emailAddresses() !== []) {
            try {
                if (! MailSettings::fresh()) {
                    throw new RuntimeException('email (SMTP) is not set up under Settings → Email.');
                }
                Mail::to($schedule->emailAddresses())->send(new ScheduledReportMail($schedule, $file));
                $file->emailed_at = now();
            } catch (Throwable $e) {
                $errors[] = 'Email: '.$e->getMessage();
            }
        }
        if ($schedule->sftp_enabled) {
            try {
                $this->upload($schedule, $file);
                $file->uploaded_at = now();
            } catch (Throwable $e) {
                $errors[] = 'SFTP: '.$e->getMessage();
            }
        }
        $file->error = $errors === [] ? null : mb_substr(implode(' ', $errors), 0, 2000);
        $file->save();
    }

    /** Upload to <sftp_path>/<filename> (the directory is made if needed). */
    public function upload(ReportSchedule $schedule, ReportFile $file): void
    {
        $sftp = new Filesystem(new SftpAdapter(self::connection($schedule), rtrim($schedule->sftp_path ?: '.', '/') ?: '/'));
        $stream = Storage::disk('local')->readStream($file->path);
        try {
            $sftp->writeStream($file->filename, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /** Settings → Scheduled reports → Test SFTP: connect and list the directory. */
    public function testSftp(ReportSchedule $schedule): void
    {
        $sftp = new Filesystem(new SftpAdapter(self::connection($schedule), rtrim($schedule->sftp_path ?: '.', '/') ?: '/'));
        $sftp->listContents('')->toArray();
    }

    private static function connection(ReportSchedule $s): SftpConnectionProvider
    {
        return new SftpConnectionProvider(
            host: (string) $s->sftp_host,
            username: (string) $s->sftp_username,
            password: $s->sftp_password ?: null,
            privateKey: $s->sftp_private_key ?: null,
            port: $s->sftp_port ?: 22,
            timeout: 15,
            maxTries: 2,
            hostFingerprint: $s->sftp_fingerprint ?: null,
        );
    }

    /** Delete files older than each schedule's retention. */
    public function prune(): int
    {
        $deleted = 0;
        foreach (ReportSchedule::query()->get(['id', 'retention_days']) as $schedule) {
            $old = $schedule->files()->where('created_at', '<', now()->subDays(max(1, $schedule->retention_days)))->get();
            foreach ($old as $file) {
                $file->delete(); // removes the CSV too
                $deleted++;
            }
        }

        return $deleted;
    }
}
