<?php

namespace App\Services;

use App\Models\Backup;
use App\Services\Freeswitch\EventSocket;
use App\Support\BackupSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The server's health (Settings → System health, the server alert rules and
 * the Prometheus metrics for Grafana).
 *
 * The scheduler does the reading, because php-fpm can't talk to FreeSWITCH or
 * open network connections (SELinux):
 *  - sample(), every 15 seconds: CPU, memory, disk, network and FreeSWITCH.
 *  - check(), every minute: the queue, call records waiting to be saved,
 *    backups, the certificate, the monthly call record tables, database size.
 * Pages, alerts and metrics only read the cached results. If the readings
 * stop coming, the scheduler itself has stopped (see checks()).
 */
class SystemHealth
{
    public const FAST = 'softswitch.health.fast';

    public const SLOW = 'softswitch.health.slow';

    public const CERTIFICATE = 'softswitch.health.certificate';

    /** The SIP profiles FreeSWITCH should always be running (sofia.conf.xml). */
    public const PROFILES = ['carrier', 'internal', 'selftest'];

    /** Readings older than this mean the scheduler isn't running. */
    public const STALE_SECONDS = 180;

    public function __construct(private SystemStats $stats, private EventSocket $esl) {}

    // --- readings (scheduler) ---

    /** Every 15 seconds: CPU, memory, disk, network and FreeSWITCH. */
    public function sample(): array
    {
        $previous = Cache::get(self::FAST);
        $now = microtime(true);
        $elapsed = is_array($previous) ? $now - (float) $previous['t'] : 0;

        $cpu = $this->stats->cpuTimes();
        $cpuPercent = null;
        if ($cpu !== null && $elapsed > 0 && is_array($previous['raw_cpu'] ?? null)) {
            [$busy0, $total0] = $previous['raw_cpu'];
            if ($cpu[1] > $total0) {
                $cpuPercent = round(max(0, min(100, ($cpu[0] - $busy0) / ($cpu[1] - $total0) * 100)), 1);
            }
        }

        $network = [];
        foreach ($this->stats->network() as $name => $n) {
            $before = $previous['network'][$name] ?? null;
            $rate = fn (string $k) => $before && $elapsed > 0 && $n[$k] >= $before[$k] ? (int) round(($n[$k] - $before[$k]) * 8 / $elapsed) : null;
            $network[$name] = [...$n, 'rx_bps' => $rate('rx'), 'tx_bps' => $rate('tx')];
        }

        $mem = $this->stats->meminfo();
        $memTotal = ($mem['MemTotal'] ?? 0) * 1024;
        $memAvailable = ($mem['MemAvailable'] ?? $mem['MemFree'] ?? 0) * 1024;
        $swapTotal = ($mem['SwapTotal'] ?? 0) * 1024;
        $diskTotal = (int) (@disk_total_space($this->stats->diskPath()) ?: 0);
        $diskFree = (int) (@disk_free_space($this->stats->diskPath()) ?: 0);
        $load = preg_split('/\s+/', trim((string) @file_get_contents('/proc/loadavg')));

        $freeswitch = $this->freeswitch();
        // How long FreeSWITCH has been unreachable (a restart takes a few seconds).
        $freeswitch['down_since'] = $freeswitch['up'] ? null : ($previous['freeswitch']['down_since'] ?? now()->toIso8601String());

        $sample = [
            'at' => now()->toIso8601String(),
            't' => $now,
            'cpu' => $cpuPercent,
            'raw_cpu' => $cpu,
            'cores' => $this->stats->cores(),
            'load' => [(float) ($load[0] ?? 0), (float) ($load[1] ?? 0), (float) ($load[2] ?? 0)],
            'memory' => ['total' => $memTotal, 'used' => max(0, $memTotal - $memAvailable)],
            'swap' => ['total' => $swapTotal, 'used' => max(0, $swapTotal - ($mem['SwapFree'] ?? 0) * 1024)],
            'disk' => ['total' => $diskTotal, 'used' => max(0, $diskTotal - $diskFree)],
            'network' => $network,
            'freeswitch' => $freeswitch,
        ];
        Cache::put(self::FAST, $sample, now()->addDay());

        return $sample;
    }

    /** @return array<string, mixed> FreeSWITCH's own status, or up=false */
    public function freeswitch(): array
    {
        try {
            [$status, $sofia] = $this->esl->apiMany(['status', 'sofia status'], 3.0);
        } catch (Throwable $e) {
            return ['up' => false, 'error' => $e->getMessage()];
        }

        return ['up' => true, ...self::parseStatus($status), 'profiles' => self::parseSofia($sofia)];
    }

    /** Every minute: the slower checks. */
    public function check(): array
    {
        $result = [
            'at' => now()->toIso8601String(),
            'queue' => $this->queue(),
            'cdr' => $this->cdrs(),
            'backup' => $this->backup(),
            'certificate' => $this->certificate(),
            'partitions' => $this->partitions(),
            'database' => $this->database(),
            'ha' => HighAvailability::role() === 'primary' ? HighAvailability::backup() : null,
        ];
        Cache::put(self::SLOW, $result, now()->addDay());

        return $result;
    }

    /** @return array{pending: int, oldest_seconds: ?int, failed_24h: int} */
    private function queue(): array
    {
        if (config('queue.default') !== 'database') {
            return ['pending' => 0, 'oldest_seconds' => null, 'failed_24h' => 0];
        }
        $now = now()->getTimestamp();
        $oldest = DB::table('jobs')->whereNull('reserved_at')->where('available_at', '<=', $now)->min('available_at');

        return [
            'pending' => DB::table('jobs')->count(),
            'oldest_seconds' => $oldest ? max(0, $now - (int) $oldest) : null,
            'failed_24h' => DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count(),
        ];
    }

    /**
     * Call records FreeSWITCH could not hand to the app (after its retries it
     * keeps them as files), and when the last one arrived.
     *
     * @return array{readable: bool, waiting: int, oldest_seconds: ?int, last_at: ?string}
     */
    private function cdrs(): array
    {
        $dir = (string) config('softswitch.freeswitch.cdr_error_dir');
        $readable = $dir !== '' && is_dir($dir) && is_readable($dir);
        $files = $readable ? (glob(rtrim($dir, '/').'/*.xml') ?: []) : [];
        $oldest = $files ? min(array_map(fn ($f) => (int) @filemtime($f), $files)) : null;
        $last = DB::scalar("SELECT max(started_at) FROM cdrs WHERE started_at > now() - interval '30 days'");

        return [
            'readable' => $readable,
            'waiting' => count($files),
            'oldest_seconds' => $oldest ? max(0, time() - $oldest) : null,
            'last_at' => $last ? CarbonImmutable::parse($last)->toIso8601String() : null,
        ];
    }

    /** @return array<string, mixed> */
    private function backup(): array
    {
        $last = Backup::query()->whereIn('status', ['done', 'failed'])->latest('id')->first();
        $ok = Backup::query()->where('status', 'done')->latest('id')->first();

        return [
            'enabled' => (bool) BackupSettings::get('enabled'),
            'time' => BackupSettings::get('time').' '.BackupSettings::get('timezone'),
            'last_ok_at' => $ok?->finished_at?->toIso8601String(),
            'last_status' => $last?->status,
            'last_at' => ($last?->finished_at ?? $last?->started_at)?->toIso8601String(),
            'last_error' => $last?->status === 'failed' ? $last->error : null,
        ];
    }

    /**
     * The certificate nginx actually serves (checked hourly over a local TLS
     * connection, so it's right whichever way it was installed).
     *
     * @return array{expires: ?string, days: ?int, issuer: ?string, error: ?string, checked_at: string}
     */
    public function certificate(bool $force = false): array
    {
        $cached = Cache::get(self::CERTIFICATE);
        if (! $force && is_array($cached) && now()->subHour()->lt($cached['checked_at'])) {
            return $cached;
        }
        $host = config('softswitch.hostname') ?: 'localhost';
        $result = ['expires' => null, 'days' => null, 'issuer' => null, 'error' => null, 'checked_at' => now()->toIso8601String()];
        $context = stream_context_create(['ssl' => [
            'capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false, 'peer_name' => $host, 'SNI_enabled' => true,
        ]]);
        $socket = @stream_socket_client('ssl://127.0.0.1:443', $errno, $error, 5, STREAM_CLIENT_CONNECT, $context);
        $cert = $socket ? (stream_context_get_params($socket)['options']['ssl']['peer_certificate'] ?? null) : null;
        $info = $cert ? openssl_x509_parse($cert) : false;
        if ($socket) {
            fclose($socket);
        }
        if (! $info || empty($info['validTo_time_t'])) {
            $result['error'] = $socket ? 'No certificate was served.' : "Could not connect to this server's HTTPS port ({$error}).";
        } else {
            $expires = CarbonImmutable::createFromTimestamp($info['validTo_time_t']);
            $result['expires'] = $expires->toIso8601String();
            $result['days'] = (int) floor(now()->diffInSeconds($expires, false) / 86400);
            $result['issuer'] = $info['issuer']['O'] ?? $info['issuer']['CN'] ?? null;
        }
        Cache::put(self::CERTIFICATE, $result, now()->addDay());

        return $result;
    }

    /** @return array{next_month: bool, months_ahead: int, waiting: int} */
    private function partitions(): array
    {
        $month = now('UTC')->startOfMonth();
        $ahead = 0;
        while ($ahead < 24 && DB::scalar('SELECT to_regclass(?) IS NOT NULL', ['cdrs_'.$month->copy()->addMonths($ahead + 1)->format('\yY\mm')])) {
            $ahead++;
        }
        $hasDefault = (bool) DB::scalar("SELECT to_regclass('cdrs_default') IS NOT NULL");

        return [
            'next_month' => $ahead > 0,
            'months_ahead' => $ahead,
            'waiting' => $hasDefault ? (int) DB::scalar('SELECT count(*) FROM cdrs_default') : 0,
        ];
    }

    /** @return array{bytes: int, months: list<array{month: string, rows: int, bytes: int}>} */
    private function database(): array
    {
        $months = [];
        foreach (['cdrs', 'cdr_attempts'] as $table) {
            foreach (CdrPartitions::list($table) as $p) {
                $key = substr($p['name'], strlen($table) + 1);
                $months[$key] ??= ['month' => $key, 'rows' => 0, 'bytes' => 0];
                $months[$key]['bytes'] += $p['bytes'];
                if ($table === 'cdrs') {
                    $months[$key]['rows'] += $p['rows'];
                }
            }
        }
        krsort($months);

        return ['bytes' => (int) DB::scalar('SELECT pg_database_size(current_database())'), 'months' => array_values($months)];
    }

    // --- parsing FreeSWITCH output ---

    /** @return array<string, mixed> from the "status" command */
    public static function parseStatus(string $s): array
    {
        $out = ['uptime' => null, 'version' => null, 'sessions' => null, 'sessions_peak' => null, 'sessions_total' => null,
            'sessions_per_second' => null, 'max_sessions_per_second' => null, 'max_sessions' => null, 'idle_cpu' => null];
        if (preg_match('/UP (\d+) years?, (\d+) days?, (\d+) hours?, (\d+) minutes?, (\d+) seconds?/', $s, $m)) {
            $out['uptime'] = (int) $m[1] * 31536000 + (int) $m[2] * 86400 + (int) $m[3] * 3600 + (int) $m[4] * 60 + (int) $m[5];
        }
        if (preg_match('/Version ([^\s)]+)/', $s, $m)) {
            $out['version'] = $m[1];
        }
        if (preg_match('/(\d+) session\(s\) since startup/', $s, $m)) {
            $out['sessions_total'] = (int) $m[1];
        }
        if (preg_match('/^(\d+) session\(s\) - peak (\d+)/m', $s, $m)) {
            [$out['sessions'], $out['sessions_peak']] = [(int) $m[1], (int) $m[2]];
        }
        if (preg_match('/(\d+) session\(s\) per Sec out of max (\d+)/', $s, $m)) {
            [$out['sessions_per_second'], $out['max_sessions_per_second']] = [(int) $m[1], (int) $m[2]];
        }
        if (preg_match('/(\d+) session\(s\) max/', $s, $m)) {
            $out['max_sessions'] = (int) $m[1];
        }
        if (preg_match('/min idle cpu ([\d.]+)\/([\d.]+)/', $s, $m)) {
            $out['idle_cpu'] = (float) $m[2];
        }

        return $out;
    }

    /** @return array<string, string> SIP profile => state (RUNNING...), from "sofia status" */
    public static function parseSofia(string $s): array
    {
        $out = [];
        foreach (explode("\n", $s) as $line) {
            $f = array_map('trim', explode("\t", $line));
            if (count($f) >= 4 && $f[1] === 'profile') {
                $out[$f[0]] = strtok($f[3], ' ') ?: $f[3];
            }
        }

        return $out;
    }

    // --- what it means ---

    /** @return array{fast: ?array, slow: ?array} the last readings */
    public static function readings(): array
    {
        $fast = Cache::get(self::FAST);
        $slow = Cache::get(self::SLOW);
        if (is_array($fast)) {
            unset($fast['raw_cpu']);
        }

        return ['fast' => is_array($fast) ? $fast : null, 'slow' => is_array($slow) ? $slow : null];
    }

    /**
     * Each check with its state (ok, warning, critical, off or unknown) and
     * whether it should raise its alert rule. Thresholds come from the alert
     * rules (Alerts → Rules), so the page and the alerts agree.
     *
     * @return list<array{key: string, label: string, state: string, summary: string, rule: ?string, alert: bool, details: array<string, mixed>}>
     */
    public static function checks(): array
    {
        ['fast' => $fast, 'slow' => $slow] = self::readings();
        $params = DB::table('alert_rules')->pluck('params', 'type')->map(fn ($p) => json_decode((string) $p, true) ?: []);
        $p = fn (string $rule, string $key, int|float $default) => $params[$rule][$key] ?? $default;
        $age = fn (?array $r) => $r ? (int) CarbonImmutable::parse($r['at'])->diffInSeconds(now(), true) : null;
        $fastFresh = $fast && $age($fast) <= self::STALE_SECONDS;
        $slowFresh = $slow && $age($slow) <= self::STALE_SECONDS;
        $checks = [];
        $add = function (string $key, string $label, string $state, string $summary, ?string $rule = null, bool $alert = false, array $details = []) use (&$checks) {
            $checks[] = compact('key', 'label', 'state', 'summary', 'rule', 'alert', 'details');
        };
        $unknown = 'No reading yet.';

        // The scheduler takes every reading, so stale readings mean it has stopped.
        if (! $fast && ! $slow) {
            $add('scheduler', 'Scheduler', 'unknown', 'No readings yet. They start within a minute of the scheduler running.');
        } elseif (! $fastFresh || ! $slowFresh) {
            $add('scheduler', 'Scheduler', 'critical', 'No readings for '.self::duration(max($age($fast) ?? 0, $age($slow) ?? 0)).': the scheduler (softswitch-scheduler) has stopped, so alerts, backups and billing jobs are not running either.');
        } else {
            $add('scheduler', 'Scheduler', 'ok', 'Running.');
        }

        // FreeSWITCH and its SIP profiles.
        $fs = $fast['freeswitch'] ?? null;
        if (! $fastFresh || ! $fs) {
            $add('freeswitch', 'FreeSWITCH', 'unknown', $unknown, 'freeswitch_down');
        } elseif (! $fs['up']) {
            $down = (int) CarbonImmutable::parse($fs['down_since'] ?? now())->diffInSeconds(now(), true);
            $alert = $down >= $p('freeswitch_down', 'minutes', 2) * 60;
            $add('freeswitch', 'FreeSWITCH', 'critical', 'Not answering for '.self::duration($down).': calls are not being handled.', 'freeswitch_down', $alert, ['error' => $fs['error'] ?? null]);
        } else {
            $add('freeswitch', 'FreeSWITCH', 'ok', sprintf('Running %s, up %s, %d active call legs.', $fs['version'] ?? '', self::duration((int) $fs['uptime']), (int) $fs['sessions']),
                'freeswitch_down', false, ['version' => $fs['version'], 'sessions' => $fs['sessions'], 'max_sessions' => $fs['max_sessions']]);
            foreach (self::PROFILES as $profile) {
                $state = $fs['profiles'][$profile] ?? null;
                if ($state !== 'RUNNING') {
                    $add("profile:{$profile}", "SIP profile {$profile}", 'critical', $state ? "The {$profile} SIP profile is {$state}." : "The {$profile} SIP profile is not running.", 'freeswitch_down', true);
                }
            }
        }

        // Queue (certificates, firewall, imports, emails...).
        $q = $slow['queue'] ?? null;
        if (! $slowFresh || ! $q) {
            $add('queue', 'Background jobs', 'unknown', $unknown, 'queue_stuck');
        } else {
            $limit = $p('queue_stuck', 'minutes', 10) * 60;
            $stuck = $q['oldest_seconds'] !== null && $q['oldest_seconds'] >= $limit;
            $summary = $stuck
                ? 'Jobs have been waiting '.self::duration($q['oldest_seconds'])." ({$q['pending']} in the queue): the queue worker (softswitch-queue) is not running or is stuck."
                : ($q['pending'] ? "{$q['pending']} waiting." : 'Nothing waiting.');
            if ($q['failed_24h']) {
                $summary .= " {$q['failed_24h']} failed in the last 24 hours.";
            }
            $add('queue', 'Background jobs', $stuck ? 'critical' : ($q['failed_24h'] ? 'warning' : 'ok'), $summary, 'queue_stuck', $stuck, $q);
        }

        // Call records FreeSWITCH could not save.
        $c = $slow['cdr'] ?? null;
        if (! $slowFresh || ! $c) {
            $add('cdr', 'Call records', 'unknown', $unknown, 'cdr_not_saved');
        } elseif (! $c['readable']) {
            $add('cdr', 'Call records', 'unknown', "Can't check for call records waiting to be saved (FreeSWITCH's xml_cdr folder isn't readable).", 'cdr_not_saved', false, ['last_call' => $c['last_at']]);
        } else {
            $late = $c['waiting'] > 0 && ($c['oldest_seconds'] ?? 0) >= $p('cdr_not_saved', 'minutes', 10) * 60;
            $last = $c['last_at'] ? ' Last call '.CarbonImmutable::parse($c['last_at'])->diffForHumans().'.' : '';
            $add('cdr', 'Call records', $late ? 'critical' : ($c['waiting'] ? 'warning' : 'ok'),
                $c['waiting'] ? "{$c['waiting']} call records could not be saved and are waiting in FreeSWITCH's xml_cdr folder (oldest ".self::duration((int) $c['oldest_seconds']).').'.$last : 'Being saved.'.$last,
                'cdr_not_saved', $late, ['waiting' => $c['waiting'], 'last_call' => $c['last_at']]);
        }

        // Disk.
        if (! $fastFresh || empty($fast['disk']['total'])) {
            $add('disk', 'Disk space', 'unknown', $unknown, 'disk_space');
        } else {
            $pct = round($fast['disk']['used'] / $fast['disk']['total'] * 100, 1);
            $limit = $p('disk_space', 'percent', 90);
            $state = $pct >= $limit ? 'critical' : ($pct >= $limit - 10 ? 'warning' : 'ok');
            $add('disk', 'Disk space', $state, sprintf('%s%% used, %s free.', $pct, self::bytes($fast['disk']['total'] - $fast['disk']['used'])), 'disk_space', $pct >= $limit, ['used_percent' => $pct]);
        }

        // Backups.
        $b = $slow['backup'] ?? null;
        if (! $slowFresh || ! $b) {
            $add('backup', 'Backups', 'unknown', $unknown, 'backup_missed');
        } elseif (! $b['enabled']) {
            $add('backup', 'Backups', 'off', 'Nightly backups are turned off (Settings → Backups).', 'backup_missed');
        } elseif ($b['last_status'] === 'failed') {
            $add('backup', 'Backups', 'critical', 'The last backup failed: '.($b['last_error'] ?: 'see Settings → Backups.'), 'backup_missed', true, ['last_good' => $b['last_ok_at']]);
        } elseif (! $b['last_ok_at']) {
            $add('backup', 'Backups', 'ok', "No backup yet; the first runs at {$b['time']}.", 'backup_missed');
        } else {
            $hours = (int) floor(CarbonImmutable::parse($b['last_ok_at'])->diffInSeconds(now(), true) / 3600);
            $missed = $hours >= $p('backup_missed', 'hours', 26);
            $add('backup', 'Backups', $missed ? 'critical' : 'ok', $missed ? "No backup for {$hours} hours." : 'Last backup '.CarbonImmutable::parse($b['last_ok_at'])->diffForHumans().'.',
                'backup_missed', $missed, ['last_good' => $b['last_ok_at']]);
        }

        // Certificate.
        $cert = $slow['certificate'] ?? null;
        if (! $slowFresh || ! $cert) {
            $add('certificate', 'SSL certificate', 'unknown', $unknown, 'certificate_expiring');
        } elseif ($cert['error']) {
            $add('certificate', 'SSL certificate', 'unknown', $cert['error'], 'certificate_expiring');
        } else {
            $limit = $p('certificate_expiring', 'days', 14);
            $state = $cert['days'] < 0 ? 'critical' : ($cert['days'] < $limit ? 'critical' : ($cert['days'] < $limit * 2 ? 'warning' : 'ok'));
            $summary = $cert['days'] < 0 ? 'Expired '.CarbonImmutable::parse($cert['expires'])->diffForHumans().'.' : "Expires in {$cert['days']} days".($cert['issuer'] ? " ({$cert['issuer']})" : '').'.';
            $add('certificate', 'SSL certificate', $state, $summary, 'certificate_expiring', $cert['days'] < $limit, ['expires' => $cert['expires'], 'days' => $cert['days']]);
        }

        // Monthly call record tables.
        $part = $slow['partitions'] ?? null;
        if (! $slowFresh || ! $part) {
            $add('partitions', 'Call record months', 'unknown', $unknown, 'cdr_months_missing');
        } else {
            $summary = $part['next_month'] ? "Ready {$part['months_ahead']} months ahead." : "Next month's call record table hasn't been made (it's made nightly at 00:20 UTC). Calls are still saved, into the catch-all table.";
            if ($part['waiting']) {
                $summary .= " {$part['waiting']} records are in the catch-all table and will be filed into their month tonight.";
            }
            $add('partitions', 'Call record months', $part['next_month'] ? 'ok' : 'critical', $summary, 'cdr_months_missing', ! $part['next_month'], $part);
        }

        // High availability (alerted by the existing "Backup server not in sync" rule).
        if (HighAvailability::role() === 'primary' && $slowFresh && is_array($slow['ha'] ?? null)) {
            $ha = $slow['ha'];
            $add('ha', 'Backup server', $ha['connected'] ? 'ok' : 'critical',
                $ha['connected'] ? 'Connected, '.self::duration((int) $ha['lag']).' behind.' : 'Not connected.', null, false, $ha);
        }

        return $checks;
    }

    public static function duration(int $seconds): string
    {
        return match (true) {
            $seconds < 120 => "{$seconds} seconds",
            $seconds < 7200 => intdiv($seconds, 60).' minutes',
            $seconds < 172800 => intdiv($seconds, 3600).' hours',
            default => intdiv($seconds, 86400).' days',
        };
    }

    public static function bytes(int|float $b): string
    {
        foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $unit) {
            if ($b < 1024 || $unit === 'TB') {
                return ($unit === 'B' ? (int) $b : round($b, 1)).' '.$unit;
            }
            $b /= 1024;
        }

        return '';
    }
}
