<?php

namespace App\Services;

use App\Models\Endpoint;
use App\Support\Dns;
use App\Support\IpNet;
use Illuminate\Support\Facades\DB;

/**
 * Keeps endpoint_addresses equal to the IPs of every endpoint's hosts
 * (hostnames resolved), used to identify calls an endpoint sends us and to
 * let them through the firewall. A failed lookup keeps the last known IPs.
 */
class EndpointSync
{
    /** @return array{endpoints: int, addresses: int} */
    public function sync(): array
    {
        $count = 0;
        $endpoints = Endpoint::query()->get();
        foreach ($endpoints as $endpoint) {
            $ips = [];
            foreach ($endpoint->hosts ?? [] as $host) {
                $ip = IpNet::normalize($host);
                array_push($ips, ...($ip !== null ? [$ip] : array_filter(array_map(fn ($i) => IpNet::normalize($i), Dns::resolve($host)))));
            }
            $ips = array_values(array_unique($ips));
            $hostnames = array_filter($endpoint->hosts ?? [], fn ($h) => IpNet::normalize($h) === null);
            if ($ips === [] && $hostnames !== []) {
                $count += $endpoint->addresses()->count();

                continue; // DNS failed: keep what we had
            }
            DB::transaction(function () use ($endpoint, $ips) {
                $endpoint->addresses()->delete();
                foreach ($ips as $ip) {
                    $endpoint->addresses()->create(['ip' => $ip]);
                }
            });
            $count += count($ips);
        }

        return ['endpoints' => $endpoints->count(), 'addresses' => $count];
    }
}
