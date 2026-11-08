<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A receiving device is identified by its own settings only; a shared server
 * (e.g. a multi-tenant PBX with 100 customers behind one IP) authorises
 * nothing by itself:
 *   domain   its FROM domain rule, from whichever server sends the call
 *   number   its caller number rule (exact format)
 *   did      endpoints.match_dids: the caller ID is one of the customer's own
 *            active DIDs (for systems that send no usable FROM domain);
 *            +1 / 1 / 10-digit forms all match the stored 1NXXNXXXXXX
 *   ip       its IP/FQDN rule, by source address
 * Which servers can reach the switch at all is still up to the firewall.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('endpoints', function (Blueprint $table) {
            $table->boolean('match_dids')->default(false)->after('add_one');
        });

        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS softswitch_identify_endpoint(inet, text, text);
            CREATE FUNCTION softswitch_identify_endpoint(p_ip inet, p_from_host text, p_caller text)
            RETURNS TABLE (endpoint_id bigint, account_id bigint, matched text, caller_id_number text, max_outbound integer,
                           max_total integer, max_channels integer, max_cps numeric, add_one boolean)
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
                WITH devices AS (
                    SELECT e.id, e.account_id, e.caller_id_number, e.max_outbound, e.max_total, a.max_channels, a.max_cps, e.add_one, e.match_dids
                    FROM endpoints e JOIN accounts a ON a.id = e.account_id
                    WHERE e.kind = 'device' AND e.mode = 'receiving' AND e.enabled AND a.is_customer
                ), caller AS (
                    SELECT regexp_replace(coalesce(p_caller, ''), '[^0-9]', '', 'g') AS digits
                ), hits AS (
                    SELECT d.id, d.account_id, d.caller_id_number, d.max_outbound, d.max_total, d.max_channels, d.max_cps, d.add_one,
                           r.type::text AS type, CASE r.type WHEN 'domain' THEN 1 WHEN 'number' THEN 2 ELSE 4 END AS rank
                    FROM endpoint_rules r JOIN devices d ON d.id = r.endpoint_id
                    WHERE (r.type = 'domain' AND r.value = lower(coalesce(p_from_host, '')))
                       OR (r.type = 'number' AND r.value = coalesce(p_caller, ''))
                       OR (r.type = 'ip' AND EXISTS (SELECT 1 FROM endpoint_addresses ea
                                                     WHERE ea.endpoint_id = d.id AND ea.purpose = 'ip_rule' AND ea.ip = p_ip))
                    UNION ALL
                    SELECT d.id, d.account_id, d.caller_id_number, d.max_outbound, d.max_total, d.max_channels, d.max_cps, d.add_one, 'did', 3
                    FROM devices d, caller c
                    WHERE d.match_dids AND length(c.digits) >= 7
                      AND EXISTS (SELECT 1 FROM pbx_dids x
                                  WHERE x.account_id = d.account_id AND x.status = 'active'
                                    AND x.number IN (c.digits, '1' || c.digits))
                )
                SELECT id, account_id, type, caller_id_number::text, max_outbound::integer, max_total::integer, max_channels, max_cps, add_one
                FROM hits ORDER BY rank, id LIMIT 1
            $$;
            REVOKE ALL ON FUNCTION softswitch_identify_endpoint(inet, text, text) FROM PUBLIC;
        SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('This migration cannot be reversed.');
    }
};
