<?php

namespace App\Support;

/** Hostname lookups (A and AAAA), replaceable in tests with Dns::fake(). */
final class Dns
{
    /** @var array<string, list<string>>|null */
    private static ?array $fake = null;

    /** @return list<string> */
    public static function resolve(string $host): array
    {
        if (self::$fake !== null) {
            return self::$fake[strtolower($host)] ?? [];
        }
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        return array_values(array_unique(array_filter(array_map(fn ($r) => $r['ip'] ?? $r['ipv6'] ?? null, $records))));
    }

    /** @param  array<string, list<string>>|null  $answers  host => IPs; null turns the fake off */
    public static function fake(?array $answers): void
    {
        self::$fake = $answers;
    }
}
