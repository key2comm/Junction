<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Emergency and non-emergency 3-digit numbers are allowed under every
 * dialing rule: 211, 311, 511, 711, 811, 911, 933 and 988.
 *
 * Before this, Canada/USA-only customers were refused them because the
 * country lookup read them as country codes (911 as India +91, 988 as Iran
 * +98, 711 as Russia +7...). They are now Canada/USA calls, so unlimited or
 * included Canada/USA minutes on a billing profile cover them too.
 *
 * softswitch_route(): as in 000030, with that one change to the dialing rules check.
 */
return new class extends Migration
{
    public const SERVICE_CODES = ['211', '311', '511', '711', '811', '911', '933', '988'];

    public function up(): void
    {
        DB::unprepared(self::routeFunction(serviceCodes: true));
    }

    public function down(): void
    {
        DB::unprepared(self::routeFunction(serviceCodes: false));
    }

    private static function routeFunction(bool $serviceCodes): string
    {
        $previous = require database_path('migrations/2026_11_14_000030_billing_profiles.php');
        $sql = (new ReflectionMethod($previous, 'routeFunction'))->invoke(null, true);
        if (! $serviceCodes) {
            return $sql;
        }
        $from = "                IF acct.dialing_scope <> 'international' THEN\n";
        if (substr_count($sql, $from) !== 1) {
            throw new RuntimeException('softswitch_route patch point not found once: dialing rules check');
        }
        $codes = "'".implode("', '", self::SERVICE_CODES)."'";

        return str_replace($from, <<<PLPGSQL
                -- Emergency and non-emergency 3-digit numbers: always allowed, as Canada/USA calls.
                IF num IN ({$codes}) THEN
                    dest_country := 'NANP';
                ELSIF acct.dialing_scope <> 'international' THEN

PLPGSQL, $sql);
    }
};
