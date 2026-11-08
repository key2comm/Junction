<?php

namespace App\Services;

use App\Models\AccountIp;
use App\Models\EndpointAddress;
use App\Models\FirewallEntry;
use App\Models\Gateway;
use App\Support\Dns;
use App\Support\IpNet;
use App\Support\Settings;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Talks to the root helper softswitch-firewall (through sudo, from the queue
 * worker only):
 *
 *  - apply(): the carrier allowlist (nftables sets for the carrier SIP port) =
 *    enabled customer IPs + every enabled carrier gateway host (hostnames
 *    resolved) + PBXs and devices' IP receiving rules + static IPs from the
 *    installer config.
 *  - applyPolicy(): Settings -> Firewall (whitelist, blacklist, lockdown); see
 *    FirewallPolicy.
 *  - status(), confirm(), rollback(), unban().
 */
class FirewallSync
{
    /** Helper exit codes. */
    public const WAITING = 3;

    public const NOTHING_PENDING = 4;

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

        // PBXs and devices' IP/FQDN receiving rules (addresses resolved by EndpointSync).
        array_push($entries, ...EndpointAddress::query()
            ->whereHas('endpoint', fn ($q) => $q->where('enabled', true)->where(fn ($w) => $w->whereNull('account_id')
                ->orWhereHas('account', fn ($a) => $a->where('is_customer', true)->where('status', '!=', 'closed'))))
            ->pluck('ip')->all());

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
        $result = $this->helper(['apply'], implode("\n", $list)."\n");

        if (! $result->successful()) {
            throw new RuntimeException('Firewall update failed: '.$this->error($result));
        }

        return ['count' => count($list)];
    }

    /**
     * Push the whitelist, blacklist and lockdown. With $confirmWithin the
     * helper rolls it back unless confirm() runs in time. Skipped while a change
     * waits for confirmation (the pending one stays as it is).
     *
     * @return array<string, mixed>
     */
    public function applyPolicy(?int $confirmWithin = null, bool $rollbackFirst = false): array
    {
        if ($rollbackFirst) {
            $this->rollback();
        }
        if ($confirmWithin === null && Settings::get(FirewallPolicy::PENDING) !== null && FirewallPolicy::pending() !== null) {
            return ['skipped' => 'a change is waiting for confirmation'];
        }
        $this->resolveHostnames();

        $args = $confirmWithin === null ? ['policy'] : ['policy', '--confirm-within', (string) $confirmWithin];
        $result = $this->helper($args, FirewallPolicy::helperInput());
        if ($result->exitCode() === self::WAITING && $confirmWithin === null) {
            return ['skipped' => 'a change is waiting for confirmation'];
        }
        if (! $result->successful()) {
            throw new RuntimeException('Firewall update failed: '.$this->error($result));
        }

        return ['policy' => trim($result->output())];
    }

    /** Keep the policy waiting for confirmation. False if there was none (already rolled back). */
    public function confirm(): bool
    {
        $result = $this->helper(['confirm']);
        if ($result->exitCode() === self::NOTHING_PENDING) {
            return false;
        }
        if (! $result->successful()) {
            throw new RuntimeException('Firewall confirm failed: '.$this->error($result));
        }

        return true;
    }

    public function rollback(): void
    {
        $result = $this->helper(['rollback']);
        if (! $result->successful()) {
            throw new RuntimeException('Firewall rollback failed: '.$this->error($result));
        }
    }

    /** @return array{pending: bool, bans: list<array>, ssh_clients: list<string>, utc_offset?: string} */
    public function status(): array
    {
        $result = $this->helper(['status']);
        $data = json_decode($result->output(), true);
        if (! $result->successful() || ! is_array($data)) {
            throw new RuntimeException('Firewall status failed: '.$this->error($result));
        }

        return $data;
    }

    public function unban(string $ip): void
    {
        if (IpNet::normalize($ip) === null || str_contains($ip, '/')) {
            throw new RuntimeException("Not an IP address: {$ip}");
        }
        $result = $this->helper(['unban', $ip]);
        if (! $result->successful()) {
            throw new RuntimeException('Unban failed: '.$this->error($result));
        }
    }

    /** Look up whitelist/blacklist hostnames again (their IPs may change). */
    public function resolveHostnames(): void
    {
        $changed = false;
        foreach (FirewallEntry::query()->get() as $entry) {
            if (! $entry->isHostname()) {
                continue;
            }
            $ips = array_values(array_filter(array_map(fn ($ip) => IpNet::normalize($ip), ($this->resolver)($entry->value))));
            sort($ips);
            // A failed lookup keeps the last known addresses rather than dropping them.
            if ($ips !== [] && $ips !== ($entry->resolved ?? [])) {
                $entry->forceFill(['resolved' => $ips, 'resolved_at' => now()])->save();
                $changed = true;
            } elseif ($ips !== []) {
                $entry->forceFill(['resolved_at' => now()])->save();
            }
        }
        if ($changed) {
            FirewallPolicy::forgetTrusted();
        }
    }

    /** @return list<string> */
    public static function resolve(string $host): array
    {
        return Dns::resolve($host);
    }

    private function helper(array $args, ?string $input = null): \Illuminate\Contracts\Process\ProcessResult
    {
        $process = Process::timeout(60);
        if ($input !== null) {
            $process = $process->input($input);
        }

        return $process->run(['sudo', '-n', config('softswitch.firewall.helper'), ...$args]);
    }

    private function error(\Illuminate\Contracts\Process\ProcessResult $result): string
    {
        return trim($result->errorOutput() ?: $result->output()) ?: 'exit code '.$result->exitCode();
    }
}
