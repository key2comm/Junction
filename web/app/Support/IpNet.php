<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\IpUtils;

/** IP address / CIDR helpers for the firewall lists. */
final class IpNet
{
    /** Narrowest networks allowed in a list (wider ones are refused, as in softswitch-firewall). */
    public const MIN_PREFIX = [4 => 8, 6 => 32];

    /**
     * Canonical form of an IP or CIDR ("10.1.2.3/24" -> "10.1.2.0/24",
     * "1.2.3.4/32" -> "1.2.3.4"), or null when it isn't one.
     */
    public static function normalize(string $value): ?string
    {
        $value = strtolower(trim($value));
        [$ip, $bits] = str_contains($value, '/') ? explode('/', $value, 2) : [$value, null];
        $packed = @inet_pton($ip);
        if ($packed === false || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $max = strlen($packed) * 8;
        if ($bits === null) {
            return inet_ntop($packed);
        }
        if (! ctype_digit($bits) || (int) $bits > $max) {
            return null;
        }
        $bits = (int) $bits;
        // Clear the host bits.
        $out = '';
        for ($i = 0; $i < strlen($packed); $i++) {
            $keep = max(0, min(8, $bits - $i * 8));
            $out .= chr(ord($packed[$i]) & (0xFF << (8 - $keep)) & 0xFF);
        }

        return $bits === $max ? inet_ntop($out) : inet_ntop($out).'/'.$bits;
    }

    /** IP version of a normalized address or network. */
    public static function version(string $net): int
    {
        return str_contains($net, ':') ? 6 : 4;
    }

    public static function prefix(string $net): int
    {
        return str_contains($net, '/') ? (int) explode('/', $net)[1] : (self::version($net) === 6 ? 128 : 32);
    }

    public static function tooBroad(string $net): bool
    {
        return self::prefix($net) < self::MIN_PREFIX[self::version($net)];
    }

    /** Whether two IPs/networks overlap. */
    public static function overlaps(string $a, string $b): bool
    {
        if (self::version($a) !== self::version($b)) {
            return false;
        }
        $base = fn (string $n) => explode('/', $n)[0];

        return IpUtils::checkIp($base($a), $b) || IpUtils::checkIp($base($b), $a);
    }

    /** @param  list<string>  $nets */
    public static function inAny(?string $ip, array $nets): bool
    {
        return $ip !== null && $nets !== [] && IpUtils::checkIp($ip, $nets);
    }

    public static function isHostname(string $value): bool
    {
        return (bool) preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,62}$/', strtolower(trim($value)));
    }
}
