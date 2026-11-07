<?php

namespace App\Services;

use App\Models\AccountIp;
use App\Models\Gateway;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Keeps the firewall's carrier allowlist (nftables sets used for the carrier
 * SIP port) equal to: enabled customer IPs + every enabled vendor gateway host
 * (hostnames resolved) + static IPs from the installer config. The root helper
 * softswitch-firewall validates the list and swaps the sets atomically.
 */
class FirewallSync
{
    /** @var callable(string): list<string> */
    private $resolver;

    public function __construct(?callable $resolver = null)
    {
        $this->resolver = $resolver ?? fn (string $host) => self::resolve($host);
    }

    /** @return list<string> sorted, de-duplicated IPs/CIDRs */
    public function allowlist(): array
    {
        $entries = AccountIp::query()->where('enabled', true)
            ->whereHas('account', fn ($q) => $q->where('is_customer', true)->where('status', '!=', 'closed'))
            ->pluck('ip')->all();

        $hosts = Gateway::query()->where('enabled', true)
            ->whereHas('trunk', fn ($q) => $q->where('enabled', true))
            ->distinct()->pluck('host');
        foreach ($hosts as $host) {
            array_push($entries, ...(filter_var($host, FILTER_VALIDATE_IP) ? [$host] : ($this->resolver)($host)));
        }

        foreach (preg_split('/[\s,]+/', (string) config('softswitch.static_carrier_ips'), -1, PREG_SPLIT_NO_EMPTY) as $ip) {
            $entries[] = $ip;
        }

        $entries = array_values(array_unique(array_map('strtolower', $entries)));
        sort($entries);

        return $entries;
    }

    /** @return array{count: int} */
    public function apply(): array
    {
        $list = $this->allowlist();
        $result = Process::timeout(30)->input(implode("\n", $list)."\n")
            ->run(['sudo', '-n', config('softswitch.firewall.helper'), 'apply']);

        if (! $result->successful()) {
            throw new RuntimeException('Firewall update failed: '.trim($result->errorOutput() ?: $result->output()));
        }

        return ['count' => count($list)];
    }

    /** @return list<string> */
    public static function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        return array_values(array_filter(array_map(fn ($r) => $r['ip'] ?? $r['ipv6'] ?? null, $records)));
    }
}
