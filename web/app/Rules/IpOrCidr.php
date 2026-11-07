<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An IPv4/IPv6 address or CIDR range, e.g. 198.51.100.7, 198.51.100.0/24,
 * 2001:db8::/32. Host bits set in a CIDR (198.51.100.7/24) are rejected so
 * that what is stored is what was meant.
 */
class IpOrCidr implements ValidationRule
{
    public function __construct(private int $minIpv4Mask = 8, private int $minIpv6Mask = 32) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $value = trim((string) $value);
        [$ip, $mask] = array_pad(explode('/', $value, 2), 2, null);

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            $fail('Enter an IP address or CIDR range, e.g. 198.51.100.7 or 198.51.100.0/24.');

            return;
        }
        if ($mask === null) {
            return;
        }

        $v6 = str_contains($ip, ':');
        $bits = $v6 ? 128 : 32;
        $min = $v6 ? $this->minIpv6Mask : $this->minIpv4Mask;
        if (! ctype_digit($mask) || (int) $mask < $min || (int) $mask > $bits) {
            $fail("The prefix length must be between /{$min} and /{$bits}.");

            return;
        }

        // Host bits must be zero.
        $packed = inet_pton($ip);
        $fullBytes = intdiv((int) $mask, 8);
        $remainder = (int) $mask % 8;
        for ($i = $fullBytes; $i < strlen($packed); $i++) {
            $byte = ord($packed[$i]);
            $allowed = ($i === $fullBytes && $remainder) ? (0xFF << (8 - $remainder)) & 0xFF : 0;
            if (($byte & ~$allowed & 0xFF) !== 0) {
                $fail("{$value} has host bits set; did you mean the network address?");

                return;
            }
        }
    }
}
