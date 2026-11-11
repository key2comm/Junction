<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * The server's own health for the dashboard: CPU, memory, disk, load.
 *
 * The scheduler calls sample() every minute (it runs outside php-fpm's
 * SELinux domain, and CPU use needs two readings of /proc/stat anyway, so
 * each sample is the average over the minute since the last one). The
 * dashboard only reads the cached result.
 */
class SystemStats
{
    public const CACHE_KEY = 'softswitch.system_stats';

    /** One hour of one-minute CPU samples for the sparkline. */
    public const HISTORY = 60;

    public function __construct(private string $proc = '/proc', private string $disk = '/', private string $etc = '/etc') {}

    /**
     * What this server is: hostname, public IP, OS and the app release.
     * Static facts, read on each request (no scheduler needed).
     *
     * @return array{hostname: string, ip: string|null, os: string|null, version: string}
     */
    public function host(): array
    {
        $os = null;
        $release = @file_get_contents($this->etc.'/os-release');
        if ($release !== false && preg_match('/^PRETTY_NAME="?([^"\n]+)"?$/m', $release, $m)) {
            $os = $m[1];
        }

        return [
            'hostname' => config('softswitch.hostname') ?: (gethostname() ?: 'unknown'),
            'ip' => config('softswitch.public_ip') ?: null,
            'os' => $os ?? (PHP_OS_FAMILY.' '.php_uname('r')),
            'version' => (string) config('softswitch.version'),
        ];
    }

    /** @return array<string, mixed>|null the last sample, or null before the first one */
    public static function current(): ?array
    {
        $stats = Cache::get(self::CACHE_KEY);

        return is_array($stats) ? array_diff_key($stats, ['raw_cpu' => true]) : null;
    }

    /** Take a reading and store it. */
    public function sample(): array
    {
        $previous = Cache::get(self::CACHE_KEY);
        $cpu = $this->cpuTimes();
        $cpuPercent = null;
        if ($cpu !== null && is_array($previous['raw_cpu'] ?? null)) {
            [$busy0, $total0] = $previous['raw_cpu'];
            [$busy1, $total1] = $cpu;
            if ($total1 > $total0) {
                $cpuPercent = round(max(0, min(100, ($busy1 - $busy0) / ($total1 - $total0) * 100)), 1);
            }
        }

        $history = is_array($previous['cpu_history'] ?? null) ? $previous['cpu_history'] : [];
        if ($cpuPercent !== null) {
            $history[] = [now()->getTimestamp(), $cpuPercent];
            $history = array_slice($history, -self::HISTORY);
        }

        $mem = $this->meminfo();
        $memTotal = ($mem['MemTotal'] ?? 0) * 1024;
        $memAvailable = ($mem['MemAvailable'] ?? $mem['MemFree'] ?? 0) * 1024;
        $swapTotal = ($mem['SwapTotal'] ?? 0) * 1024;
        $swapFree = ($mem['SwapFree'] ?? 0) * 1024;
        $diskTotal = @disk_total_space($this->disk) ?: 0;
        $diskFree = @disk_free_space($this->disk) ?: 0;
        $load = preg_split('/\s+/', trim((string) @file_get_contents("{$this->proc}/loadavg")));
        $uptime = (float) strtok((string) @file_get_contents("{$this->proc}/uptime"), ' ');

        $stats = [
            'at' => now()->toIso8601String(),
            'cpu' => $cpuPercent,
            'cpu_history' => $history,
            'cores' => $this->cores(),
            'load' => [(float) ($load[0] ?? 0), (float) ($load[1] ?? 0), (float) ($load[2] ?? 0)],
            'memory' => ['total' => $memTotal, 'used' => max(0, $memTotal - $memAvailable)],
            'swap' => ['total' => $swapTotal, 'used' => max(0, $swapTotal - $swapFree)],
            'disk' => ['total' => (int) $diskTotal, 'used' => (int) max(0, $diskTotal - $diskFree)],
            'uptime' => (int) $uptime,
            'raw_cpu' => $cpu,
        ];
        Cache::forever(self::CACHE_KEY, $stats);

        return $stats;
    }

    /** @return array{0: int, 1: int}|null [busy, total] jiffies since boot */
    private function cpuTimes(): ?array
    {
        $stat = @file_get_contents("{$this->proc}/stat");
        if ($stat === false || ! preg_match('/^cpu\s+(.+)$/m', $stat, $m)) {
            return null;
        }
        $f = array_map('intval', preg_split('/\s+/', trim($m[1])));
        // user nice system idle iowait irq softirq steal (guest time is already in user).
        $total = array_sum(array_slice($f, 0, 8));
        $idle = ($f[3] ?? 0) + ($f[4] ?? 0);

        return [$total - $idle, $total];
    }

    /** @return array<string, int> kB values */
    private function meminfo(): array
    {
        $out = [];
        foreach (explode("\n", (string) @file_get_contents("{$this->proc}/meminfo")) as $line) {
            if (preg_match('/^(\w+):\s+(\d+)/', $line, $m)) {
                $out[$m[1]] = (int) $m[2];
            }
        }

        return $out;
    }

    private function cores(): int
    {
        $stat = (string) @file_get_contents("{$this->proc}/stat");

        return max(1, preg_match_all('/^cpu\d+\s/m', $stat));
    }
}
