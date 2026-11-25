<?php

use App\Support\DialCountries;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Customer dialing rules (Accounts → Dialing rules): where an account's calls
 * may go.
 *
 *   accounts.dialing_scope       nanp           Canada and the USA only
 *                                international  anywhere
 *                                countries      Canada/USA plus dialing_countries
 *   accounts.dialing_countries   ["GB", "FR", ...] (codes from dial_countries)
 *
 * Accounts that already exist keep calling anywhere (the column default), so
 * live traffic doesn't stop; new customers start on Canada/USA only
 * (AccountRequest).
 *
 * dial_countries / dial_country_prefixes: App\Support\DialCountries.
 * softswitch_dial_country(number): the country of the longest matching prefix.
 * softswitch_route(): as in 000013, plus the dialing rules check right after
 *   the blocked prefixes (reason destination_not_allowed, SIP 403).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dial_countries', function (Blueprint $table) {
            $table->string('code', 8)->primary();
            $table->string('name');
            $table->string('region', 64);
        });
        Schema::create('dial_country_prefixes', function (Blueprint $table) {
            $table->string('prefix', 16)->primary();
            $table->string('country_code', 8);
            $table->foreign('country_code')->references('code')->on('dial_countries')->cascadeOnDelete();
        });
        DB::statement("ALTER TABLE dial_country_prefixes ADD CONSTRAINT dial_country_prefixes_digits CHECK (prefix ~ '^[0-9]{1,16}$')");
        DialCountries::sync();

        Schema::table('accounts', function (Blueprint $table) {
            $table->string('dialing_scope', 16)->default('international');
            $table->jsonb('dialing_countries')->default('[]');
        });
        DB::statement("ALTER TABLE accounts ADD CONSTRAINT accounts_dialing_scope_check CHECK (dialing_scope IN ('nanp', 'international', 'countries'))");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION softswitch_dial_country(p_number text) RETURNS text
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
                SELECT p.country_code FROM dial_country_prefixes p
                WHERE p.prefix = ANY (ARRAY(SELECT left(p_number, n) FROM generate_series(least(length(p_number), 16), 1, -1) AS n))
                ORDER BY length(p.prefix) DESC
                LIMIT 1
            $$;
            REVOKE ALL ON FUNCTION softswitch_dial_country(text) FROM PUBLIC;


            -- softswitch_route() is defined in database/sql/softswitch_route.sql (applied after every migrate).
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS softswitch_dial_country(text)');
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn(['dialing_scope', 'dialing_countries']);
        });
        Schema::dropIfExists('dial_country_prefixes');
        Schema::dropIfExists('dial_countries');
    }
};
