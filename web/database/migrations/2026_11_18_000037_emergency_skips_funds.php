<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Emergency numbers (911, 933, 988) skip the funds check: a prepaid customer
 * with no balance, or a postpaid one over the credit limit, can still make
 * them, and they're never cut off for running out of funds. They're still
 * rated and billed from the tariff after the call.
 *
 * softswitch_route(): as in 000036, with the call treated like one the plan
 * covers (no funds needed, none reserved, no time limit from the balance).
 */
return new class extends Migration
{
    public const EMERGENCY = ['911', '933', '988'];

    public function up(): void
    {
        DB::unprepared(self::routeFunction(emergency: true));
    }

    public function down(): void
    {
        DB::unprepared(self::routeFunction(emergency: false));
    }

    private static function routeFunction(bool $emergency): string
    {
        $previous = require database_path('migrations/2026_11_18_000036_dial_service_codes.php');
        $sql = (new ReflectionMethod($previous, 'routeFunction'))->invoke(null, true);
        if (! $emergency) {
            return $sql;
        }
        $from = "                -- Funds (only when the built-in billing is on).";
        if (substr_count($sql, $from) !== 1) {
            throw new RuntimeException('softswitch_route patch point not found once: funds');
        }
        $codes = "'".implode("', '", self::EMERGENCY)."'";

        return str_replace($from, <<<PLPGSQL
                -- Emergency numbers never wait on funds.
                IF num IN ({$codes}) THEN
                    covered := true;
                END IF;

{$from}
PLPGSQL, $sql);
    }
};
