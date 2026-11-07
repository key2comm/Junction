<?php

namespace App\Support;

/**
 * Exact decimal helpers for money (bcmath truncates; billing must round).
 */
final class Decimal
{
    /** Round half away from zero to $scale places. */
    public static function round(string $value, int $scale = 6): string
    {
        $half = '0.'.str_repeat('0', $scale).'5';
        $rounded = str_starts_with(ltrim($value), '-')
            ? bcsub($value, $half, $scale)
            : bcadd($value, $half, $scale);

        // Normalise "-0.000000" to "0.000000".
        return bccomp($rounded, '0', $scale) === 0 ? bcadd('0', '0', $scale) : $rounded;
    }
}
