<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * endpoints.add_one: the device dials North American numbers as 10 digits
 * (4165551234); the switch adds the 1 (14165551234) before routing. Applied to
 * calls it sends as a receiving device (route.lua) or over its SIP trunk
 * (device.lua), so softswitch_identify_endpoint / softswitch_device return it.
 * softswitch_identify_endpoint now only matches receiving devices.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('endpoints', function (Blueprint $table) {
            $table->boolean('add_one')->default(false)->after('caller_id_number');
        });

        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS softswitch_device(bigint);
            CREATE FUNCTION softswitch_device(p_id bigint)
            RETURNS TABLE (endpoint_id bigint, account_id bigint, active boolean, caller_id_number text, max_outbound integer,
                           max_total integer, max_channels integer, max_cps numeric, add_one boolean)
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
                SELECT e.id, e.account_id, e.enabled AND a.status = 'active' AND a.is_customer, e.caller_id_number::text,
                       e.max_outbound::integer, e.max_total::integer, a.max_channels, a.max_cps, e.add_one
                FROM endpoints e JOIN accounts a ON a.id = e.account_id
                WHERE e.id = p_id AND e.kind = 'device' AND e.sip_username IS NOT NULL
            $$;

            DROP FUNCTION IF EXISTS softswitch_identify_endpoint(inet, text, text);
            CREATE FUNCTION softswitch_identify_endpoint(p_ip inet, p_from_host text, p_caller text)
            RETURNS TABLE (endpoint_id bigint, account_id bigint, matched text, caller_id_number text, max_outbound integer,
                           max_total integer, max_channels integer, max_cps numeric, add_one boolean)
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
                WITH devices AS (
                    SELECT e.id, e.account_id, e.caller_id_number, e.max_outbound, e.max_total, a.max_channels, a.max_cps, e.add_one
                    FROM endpoints e JOIN accounts a ON a.id = e.account_id
                    WHERE e.kind = 'device' AND e.mode = 'receiving' AND e.enabled AND a.is_customer
                ), from_pbx AS (
                    SELECT EXISTS (
                        SELECT 1 FROM endpoint_addresses ea JOIN endpoints p ON p.id = ea.endpoint_id
                        WHERE ea.purpose = 'pbx_host' AND ea.ip = p_ip AND p.enabled
                    ) AS yes
                ), hits AS (
                    SELECT d.*, r.type, CASE r.type WHEN 'domain' THEN 1 WHEN 'number' THEN 2 ELSE 3 END AS rank
                    FROM endpoint_rules r JOIN devices d ON d.id = r.endpoint_id
                    WHERE (r.type = 'ip' AND EXISTS (SELECT 1 FROM endpoint_addresses ea
                                                     WHERE ea.endpoint_id = d.id AND ea.purpose = 'ip_rule' AND ea.ip = p_ip))
                       OR (r.type IN ('domain', 'number')
                           AND ((SELECT yes FROM from_pbx)
                                OR EXISTS (SELECT 1 FROM endpoint_addresses ea
                                           WHERE ea.endpoint_id = d.id AND ea.purpose = 'ip_rule' AND ea.ip = p_ip))
                           AND ((r.type = 'domain' AND r.value = lower(coalesce(p_from_host, '')))
                                OR (r.type = 'number' AND r.value = coalesce(p_caller, ''))))
                )
                SELECT id, account_id, type::text, caller_id_number::text, max_outbound::integer, max_total::integer, max_channels, max_cps, add_one
                FROM hits ORDER BY rank, id LIMIT 1
            $$;

            REVOKE ALL ON FUNCTION softswitch_identify_endpoint(inet, text, text) FROM PUBLIC;
            REVOKE ALL ON FUNCTION softswitch_device(bigint) FROM PUBLIC;
        SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('This migration cannot be reversed.');
    }
};
