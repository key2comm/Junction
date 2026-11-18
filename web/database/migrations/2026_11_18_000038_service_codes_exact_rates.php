<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The 3-digit service numbers (211, 311, 511, 711, 811, 911, 933, 988) only
 * match a rate for that exact number. Otherwise 911 matched a carrier's India
 * rate (91), 988 Iran (98) and so on, so any carrier with those countries
 * could get an emergency call. Now only carriers whose rates include the
 * exact number are used, and the customer's tariff needs that exact number too
 * (otherwise the call is refused: no rate). LCR group rules and blocked
 * prefixes likewise only match the exact number (or an LCR rule for everything).
 *
 * Emergency numbers (911, 933, 988) also go to a carrier that costs more than
 * the customer's rate (so a 0 rate in the tariff works): never refused for margin.
 *
 * softswitch_route(): as in 000037, with one change to the prefixes it matches.
 */
return new class extends Migration
{
    public const SERVICE_CODES = ['211', '311', '511', '711', '811', '911', '933', '988'];

    public const EMERGENCY = ['911', '933', '988'];

    public function up(): void
    {
        DB::unprepared(self::routeFunction(exact: true));
    }

    public function down(): void
    {
        DB::unprepared(self::routeFunction(exact: false));
    }

    private static function routeFunction(bool $exact): string
    {
        $previous = require database_path('migrations/2026_11_18_000037_emergency_skips_funds.php');
        $sql = (new ReflectionMethod($previous, 'routeFunction'))->invoke(null, true);
        if (! $exact) {
            return $sql;
        }
        $from = "                prefixes := ARRAY(SELECT left(num, n) FROM generate_series(length(num), 1, -1) AS n);\n";
        if (substr_count($sql, $from) !== 1) {
            throw new RuntimeException('softswitch_route patch point not found once: prefixes');
        }
        $margin = "                      AND br.rate <= sell.rate\n";
        if (substr_count($sql, $margin) !== 1) {
            throw new RuntimeException('softswitch_route patch point not found once: margin');
        }
        $emergency = "'".implode("', '", self::EMERGENCY)."'";
        $sql = str_replace($margin, "                      AND (br.rate <= sell.rate OR num IN ({$emergency}))\n", $sql);
        $codes = "'".implode("', '", self::SERVICE_CODES)."'";

        return str_replace($from, $from.<<<PLPGSQL
                -- 3-digit service numbers only match rates for that exact number (911 is not India's 91).
                IF num IN ({$codes}) THEN
                    prefixes := ARRAY[num];
                END IF;

PLPGSQL, $sql);
    }
};
