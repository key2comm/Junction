<?php

namespace App\Support;

final class Prefixes
{
    /**
     * Every leading prefix of a dialled number, longest first:
     * "4420" => ["4420", "442", "44", "4"]. Used for longest-prefix matching
     * with a plain btree index (`prefix IN (...) ORDER BY length(prefix) DESC`).
     *
     * @return list<string>
     */
    public static function of(string $number, int $maxLength = 32): array
    {
        $digits = substr((string) preg_replace('/\D/', '', $number), 0, $maxLength);
        $prefixes = [];
        for ($i = strlen($digits); $i > 0; $i--) {
            $prefixes[] = substr($digits, 0, $i);
        }

        return $prefixes;
    }
}
