<?php

namespace App\Services;

use App\Models\Endpoint;
use App\Support\Dns;
use App\Support\IpNet;
use Illuminate\Support\Facades\DB;

/**
 * Keeps endpoint_addresses equal to the IPs (hostnames resolved) of:
 *   pbx_host  every PBX's host: a trusted source for devices' domain and
 *             caller-number receiving rules
 *   ip_rule   every device's IP/FQDN receiving rule
 * Both are let through the firewall to the carrier port. A failed lookup keeps
 * the last known IPs.
 */
class EndpointSync
{
    /** @return array{endpoints: int, addresses: int} */
    public function sync(): array
    {
        $count = 0;
        $endpoints = Endpoint::query()->with('rules')->get();
        foreach ($endpoints as $endpoint) {
            $sources = $endpoint->isPbx()
                ? ['pbx_host' => $endpoint->hosts ?? []]
                : ['ip_rule' => $endpoint->rules->where('type', 'ip')->pluck('value')->all()];
            foreach ($sources as $purpose => $hosts) {
                $ips = [];
                foreach ($hosts as $host) {
                    $ip = IpNet::normalize($host);
                    array_push($ips, ...($ip !== null ? [$ip] : array_filter(array_map(fn ($i) => IpNet::normalize($i), Dns::resolve($host)))));
                }
                $ips = array_values(array_unique($ips));
                $current = $endpoint->addresses()->where('purpose', $purpose);
                $hostnames = array_filter($hosts, fn ($h) => IpNet::normalize($h) === null);
                if ($ips === [] && $hostnames !== []) {
                    $count += (clone $current)->count();

                    continue; // DNS failed: keep what we had
                }
                DB::transaction(function () use ($endpoint, $purpose, $ips) {
                    $endpoint->addresses()->where('purpose', $purpose)->delete();
                    foreach ($ips as $ip) {
                        $endpoint->addresses()->create(['purpose' => $purpose, 'ip' => $ip]);
                    }
                });
                $count += count($ips);
            }
            // An endpoint that changed kind keeps no addresses of the other purpose.
            $endpoint->addresses()->whereNotIn('purpose', array_keys($sources))->delete();
        }

        return ['endpoints' => $endpoints->count(), 'addresses' => $count];
    }
}
