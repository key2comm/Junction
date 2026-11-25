<?php

namespace App\Support;

/**
 * Settings → Backups, stored in app_settings under "backup.*" (secrets are
 * encrypted, see Settings::SECRET). Read by App\Services\BackupRunner.
 */
final class BackupSettings
{
    public const PREFIX = 'backup.';

    /** Write-only fields: never sent to the browser. */
    public const SECRETS = ['password', 'sftp_password', 'sftp_private_key', 's3_secret'];

    /** S3-compatible storage: name, endpoint (with {region}), example region. */
    public const PROVIDERS = [
        'aws' => ['label' => 'Amazon S3', 'endpoint' => 'https://s3.{region}.amazonaws.com', 'region' => 'us-east-1'],
        'digitalocean' => ['label' => 'DigitalOcean Spaces', 'endpoint' => 'https://{region}.digitaloceanspaces.com', 'region' => 'tor1'],
        'wasabi' => ['label' => 'Wasabi', 'endpoint' => 'https://s3.{region}.wasabisys.com', 'region' => 'ca-central-1'],
        'backblaze' => ['label' => 'Backblaze B2', 'endpoint' => 'https://s3.{region}.backblazeb2.com', 'region' => 'us-west-004'],
        'cloudflare' => ['label' => 'Cloudflare R2', 'endpoint' => null, 'region' => 'auto'],
        'other' => ['label' => 'Other S3-compatible (MinIO, Linode, Vultr...)', 'endpoint' => null, 'region' => 'us-east-1'],
    ];

    public const DEFAULTS = [
        'enabled' => true,
        'frequency' => 'daily',  // daily | hourly (at the minute of 'time')
        'time' => '02:00',
        'timezone' => 'UTC',
        'retention_days' => 7,
        'unencrypted_ok' => false,
        'email_to' => null,
        'sftp_enabled' => false,
        'sftp_host' => null,
        'sftp_port' => 22,
        'sftp_username' => null,
        'sftp_path' => null,
        'sftp_fingerprint' => null,
        'sftp_keep_days' => 30,
        's3_enabled' => false,
        's3_provider' => 'aws',
        's3_endpoint' => null,
        's3_region' => null,
        's3_bucket' => null,
        's3_key' => null,
        's3_path' => null,
        's3_keep_days' => 30,
    ];

    public const FREQUENCIES = ['daily', 'hourly'];

    /** Run now (Settings → Backups): the scheduler picks it up within a minute. */
    public const REQUESTED = 'backup.requested_at';

    /** @return array<string, mixed> every setting, secrets included */
    public static function all(): array
    {
        $out = [];
        foreach ([...array_keys(self::DEFAULTS), ...self::SECRETS] as $key) {
            $out[$key] = Settings::get(self::PREFIX.$key, self::DEFAULTS[$key] ?? null);
        }

        return $out;
    }

    public static function get(string $key): mixed
    {
        return Settings::get(self::PREFIX.$key, self::DEFAULTS[$key] ?? null);
    }

    /** @param  array<string, mixed>  $values  keys without the prefix */
    public static function put(array $values): void
    {
        Settings::put(collect($values)->mapWithKeys(fn ($v, $k) => [self::PREFIX.$k => $v])->all());
    }

    /** The S3 endpoint URL for the chosen provider (a custom one wins). */
    public static function s3Endpoint(array $s): string
    {
        $custom = trim((string) ($s['s3_endpoint'] ?? ''));
        if ($custom !== '') {
            return rtrim(str_contains($custom, '://') ? $custom : "https://{$custom}", '/');
        }
        $template = self::PROVIDERS[$s['s3_provider'] ?? 'aws']['endpoint'] ?? null;

        return $template ? str_replace('{region}', self::s3Region($s), $template) : '';
    }

    public static function s3Region(array $s): string
    {
        return trim((string) ($s['s3_region'] ?? '')) ?: (self::PROVIDERS[$s['s3_provider'] ?? 'aws']['region'] ?? 'us-east-1');
    }
}
