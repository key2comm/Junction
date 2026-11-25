<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Backup;
use App\Services\BackupRunner;
use App\Services\HighAvailability;
use App\Services\MailSettings;
use App\Support\BackupSettings;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Settings → Backups: the nightly database backup, kept on the server and
 * optionally emailed, uploaded by SFTP and to S3-compatible storage
 * (App\Services\BackupRunner). Secrets are write-only.
 */
class BackupController extends Controller
{
    public function edit(): Response
    {
        $s = BackupSettings::all();
        $dir = Storage::disk('local')->path(BackupRunner::DIR);

        return Inertia::render('settings/Backups', [
            'settings' => [
                ...collect($s)->except(BackupSettings::SECRETS)->all(),
                'has_password' => filled($s['password']),
                'has_sftp_password' => filled($s['sftp_password']),
                'has_sftp_key' => filled($s['sftp_private_key']),
                'has_s3_secret' => filled($s['s3_secret']),
            ],
            'backups' => Backup::query()->latest('id')->limit(100)->get()->map(fn (Backup $b) => [
                ...$b->only(['id', 'filename', 'size', 'encrypted', 'status', 'trigger', 'started_at', 'finished_at', 'emailed_at', 'sftp_at', 's3_at', 'error']),
                'available' => $b->status === 'done' && $b->fileExists(),
            ]),
            'next' => $this->nextRun($s)?->toIso8601String(),
            'requested' => (bool) Settings::get(BackupSettings::REQUESTED),
            'standby' => HighAvailability::role() === 'standby',
            'providers' => collect(BackupSettings::PROVIDERS)->map(fn ($p) => ['label' => $p['label'], 'endpoint' => $p['endpoint'], 'region' => $p['region']]),
            'timezones' => timezone_identifiers_list(),
            'mailConfigured' => MailSettings::configured(),
            'diskFree' => is_dir($dir) || is_dir(dirname($dir)) ? @disk_free_space(is_dir($dir) ? $dir : dirname($dir)) ?: null : null,
            'restore' => BackupRunner::restoreSteps('softswitch-backup-….tar'),
            'restoreEncrypted' => BackupRunner::restoreSteps('softswitch-backup-….tar.enc', true),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        BackupSettings::put($this->validated($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Backup settings saved. Use Run now to try them.']);

        return to_route('backups.edit');
    }

    /** Run now: the scheduler (which can reach the network; the web server can't) starts it within a minute. */
    public function run(): RedirectResponse
    {
        Settings::put([BackupSettings::REQUESTED => now()->toIso8601String()]);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'The backup starts within a minute. Refresh this page to see it.']);

        return to_route('backups.edit');
    }

    public function download(Backup $backup): StreamedResponse
    {
        abort_unless($backup->status === 'done' && $backup->fileExists(), 404);

        return Storage::disk('local')->download($backup->path, $backup->filename, ['Content-Type' => 'application/octet-stream']);
    }

    public function destroy(Backup $backup): RedirectResponse
    {
        $backup->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$backup->filename} deleted from this server."]);

        return to_route('backups.edit');
    }

    private function nextRun(array $s): ?CarbonImmutable
    {
        if (! $s['enabled']) {
            return null;
        }
        $runner = app(BackupRunner::class);

        return $runner->due() ? CarbonImmutable::now()->addMinute()->startOfMinute() : $runner->nextSlot();
    }

    private function validated(Request $request): array
    {
        $sftp = $request->boolean('sftp_enabled');
        $s3 = $request->boolean('s3_enabled');
        foreach (['enabled', 'sftp_enabled', 's3_enabled', 'unencrypted_ok'] as $flag) {
            $request->merge([$flag => $request->boolean($flag)]);
        }
        foreach (['sftp_host', 'sftp_fingerprint', 's3_endpoint', 's3_region', 's3_bucket', 's3_key', 's3_path', 'sftp_path'] as $field) {
            $request->merge([$field => trim((string) $request->input($field)) ?: null]);
        }
        $data = $request->validate([
            'enabled' => ['boolean'],
            'frequency' => ['sometimes', 'in:'.implode(',', BackupSettings::FREQUENCIES)],
            'time' => ['required', 'date_format:H:i'],
            'timezone' => ['required', 'timezone:all'],
            'retention_days' => ['required', 'integer', 'between:1,365'],
            'password' => ['nullable', 'string', 'min:12', 'max:200'],
            'unencrypted_ok' => ['boolean'],
            'email_to' => ['nullable', 'string', 'max:1000'],
            'sftp_enabled' => ['boolean'],
            'sftp_host' => [$sftp ? 'required' : 'nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9.\-:\[\]]+$/'],
            'sftp_port' => [$sftp ? 'required' : 'nullable', 'integer', 'between:1,65535'],
            'sftp_username' => [$sftp ? 'required' : 'nullable', 'string', 'max:128'],
            'sftp_password' => ['nullable', 'string', 'max:255'],
            'sftp_private_key' => ['nullable', 'string', 'max:20000'],
            'sftp_path' => ['nullable', 'string', 'max:255', 'not_regex:/\.\./'],
            'sftp_fingerprint' => ['nullable', 'string', 'max:128'],
            'sftp_keep_days' => ['required', 'integer', 'between:0,3650'],
            's3_enabled' => ['boolean'],
            's3_provider' => ['required', Rule::in(array_keys(BackupSettings::PROVIDERS))],
            's3_endpoint' => [$s3 && in_array($request->input('s3_provider'), ['cloudflare', 'other'], true) ? 'required' : 'nullable', 'string', 'max:255',
                'regex:#^(https?://)?[A-Za-z0-9.\-]+(:\d+)?/?$#'],
            's3_region' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9\-]+$/'],
            's3_bucket' => [$s3 ? 'required' : 'nullable', 'string', 'between:3,63', 'regex:/^[a-z0-9][a-z0-9.\-]+[a-z0-9]$/'],
            's3_key' => [$s3 ? 'required' : 'nullable', 'string', 'max:128'],
            's3_secret' => ['nullable', 'string', 'max:255'],
            's3_path' => ['nullable', 'string', 'max:255', 'not_regex:/\.\./'],
            's3_keep_days' => ['required', 'integer', 'between:0,3650'],
        ], [
            'sftp_host.regex' => 'Enter a host name or IP address.',
            'sftp_path.not_regex' => 'Use a plain directory path (no "..").',
            's3_path.not_regex' => 'Use a plain folder name (no "..").',
            's3_endpoint.regex' => 'Enter the endpoint, e.g. https://ACCOUNT-ID.r2.cloudflarestorage.com',
            's3_endpoint.required' => 'Enter the endpoint URL for this storage.',
            's3_bucket.regex' => 'Bucket names are lowercase letters, digits, dots and dashes.',
            'password.min' => 'Use at least 12 characters: anyone with the file and the password can read the whole database.',
        ]);

        $emails = BackupRunner::emails($data['email_to'] ?? null);
        foreach ($emails as $email) {
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw ValidationException::withMessages(['email_to' => "{$email} is not an email address."]);
            }
        }
        $out = [
            ...collect($data)->except(['password', 'sftp_password', 'sftp_private_key', 's3_secret', 'email_to'])->all(),
            'sftp_port' => $data['sftp_port'] ?? 22,
            'email_to' => $emails === [] ? null : implode(', ', $emails),
        ];
        // Secrets are write-only: blank keeps the saved one, the "remove" boxes clear it.
        foreach (['password' => 'clear_password', 'sftp_password' => 'clear_sftp_password', 'sftp_private_key' => 'clear_sftp_key', 's3_secret' => 'clear_s3_secret'] as $field => $clear) {
            if (($data[$field] ?? '') !== '') {
                $out[$field] = $data[$field];
            } elseif ($request->boolean($clear)) {
                $out[$field] = null;
            }
        }
        $saved = BackupSettings::all();
        $has = fn (string $f) => array_key_exists($f, $out) ? filled($out[$f]) : filled($saved[$f]);
        if ($sftp && ! $has('sftp_password') && ! $has('sftp_private_key')) {
            throw ValidationException::withMessages(['sftp_password' => 'Enter the SFTP password or a private key.']);
        }
        if ($s3 && ! $has('s3_secret')) {
            throw ValidationException::withMessages(['s3_secret' => 'Enter the secret key.']);
        }
        // A database copy leaving the server should be encrypted.
        if (($sftp || $s3 || $emails !== []) && ! $has('password') && ! $data['unencrypted_ok']) {
            throw ValidationException::withMessages(['password' => 'Set a backup password to encrypt backups sent off the server, or tick "Send them unencrypted".']);
        }

        return $out;
    }
}
