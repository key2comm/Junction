<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The routing engine, as PostgreSQL functions. FreeSWITCH (Lua) and the admin
 * UI's route tester call the same code, so what the UI shows is what calls do.
 *
 *   softswitch_identify(source_ip, number)        -> which customer sent the call
 *   softswitch_route(account, number, uuid, ...)  -> rejection reason, or the
 *                                                    ordered vendor attempts
 *   softswitch_release(uuid)                      -> free a call's fund reservation
 *
 * The functions are SECURITY DEFINER: FreeSWITCH's database role is granted
 * EXECUTE on them only (by the installer) and cannot read any table directly.
 *
 * Funds: a call reserves the cost of its maximum duration in active_calls while
 * it is up, so concurrent calls cannot spend the same balance. Available funds
 * = balance + credit limit - reservations - rated usage not yet posted to the
 * ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE UNLOGGED TABLE active_calls (
                uuid uuid PRIMARY KEY,
                account_id bigint NOT NULL,
                reserved numeric(16, 6) NOT NULL,
                max_duration integer NOT NULL,
                created_at timestamptz NOT NULL DEFAULT now()
            );
            CREATE INDEX active_calls_account_id_idx ON active_calls (account_id);

            -- migrate:fresh drops tables but not types; CASCADE also drops the
            -- function that returns it (recreated below).
            DROP TYPE IF EXISTS softswitch_route_row CASCADE;
            CREATE TYPE softswitch_route_row AS (
                status text,              -- 'ok' or 'reject'
                reason text,              -- reject reason (see below), null when ok
                attempt integer,          -- vendor attempt number (1 = first trunk to try)
                gateway_order integer,    -- order of gateways within the attempt
                trunk_id bigint,
                gateway_id bigint,
                gateway_name text,
                dial_number text,         -- number as sent to this trunk
                destination text,         -- normalised digits
                buy_rate_id bigint,
                buy_prefix text,
                buy_rate numeric,
                sell_rate_id bigint,
                sell_prefix text,
                sell_rate numeric,
                max_duration integer,     -- seconds the customer can afford (capped)
                trunk_max_channels integer,
                trunk_max_cps numeric
            );

            -- Which customer sent a call: enabled IP/CIDR match plus tech prefix.
            -- Longest tech prefix wins, then the most specific IP range.
            CREATE OR REPLACE FUNCTION softswitch_identify(p_ip inet, p_number text)
            RETURNS TABLE (account_id bigint, tech_prefix text, number text, max_channels integer, max_cps numeric)
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
                SELECT a.id, i.tech_prefix::text, substr(p_number, length(i.tech_prefix) + 1), a.max_channels, a.max_cps
                FROM account_ips i
                JOIN accounts a ON a.id = i.account_id
                WHERE i.enabled AND a.is_customer AND p_ip <<= i.ip
                  AND (i.tech_prefix = '' OR left(p_number, length(i.tech_prefix)) = i.tech_prefix)
                ORDER BY length(i.tech_prefix) DESC, masklen(i.ip) DESC
                LIMIT 1
            $$;

            -- softswitch_route() is defined in database/sql/softswitch_route.sql (applied after every migrate).

            CREATE OR REPLACE FUNCTION softswitch_release(p_uuid uuid) RETURNS void
            LANGUAGE sql VOLATILE SECURITY DEFINER SET search_path = public, pg_temp AS $$
                DELETE FROM active_calls WHERE uuid = p_uuid
            $$;

            REVOKE ALL ON FUNCTION softswitch_identify(inet, text) FROM PUBLIC;
            REVOKE ALL ON FUNCTION softswitch_release(uuid) FROM PUBLIC;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS softswitch_release(uuid);
            DROP FUNCTION IF EXISTS softswitch_route(bigint, text, uuid, timestamptz, integer);
            DROP FUNCTION IF EXISTS softswitch_identify(inet, text);
            DROP TYPE IF EXISTS softswitch_route_row;
            DROP TABLE IF EXISTS active_calls;
        SQL);
    }
};
