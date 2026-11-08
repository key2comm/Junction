<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A receiving device is identified by its own rules only: its FROM domain or
 * caller number rule matches whichever server the call comes from (shared
 * PBX entries no longer count as "trusted sources"; they only receive DIDs),
 * and its IP/FQDN rule by source address. Which servers can reach the switch
 * at all is still up to the firewall (carrier allowlist / trusted).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS softswitch_identify_endpoint(inet, text, text);
            CREATE FUNCTION softswitch_identify_endpoint(p_ip inet, p_from_host text, p_caller text)
            RETURNS TABLE (endpoint_id bigint, account_id bigint, matched text, caller_id_number text, max_outbound integer,
                           max_total integer, max_channels integer, max_cps numeric, add_one boolean)
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
                WITH devices AS (
                    SELECT e.id, e.account_id, e.caller_id_number, e.max_outbound, e.max_total, a.max_channels, a.max_cps, e.add_one
                    FROM endpoints e JOIN accounts a ON a.id = e.account_id
                    WHERE e.kind = 'device' AND e.mode = 'receiving' AND e.enabled AND a.is_customer
                ), hits AS (
                    SELECT d.*, r.type, CASE r.type WHEN 'domain' THEN 1 WHEN 'number' THEN 2 ELSE 3 END AS rank
                    FROM endpoint_rules r JOIN devices d ON d.id = r.endpoint_id
                    WHERE (r.type = 'domain' AND r.value = lower(coalesce(p_from_host, '')))
                       OR (r.type = 'number' AND r.value = coalesce(p_caller, ''))
                       OR (r.type = 'ip' AND EXISTS (SELECT 1 FROM endpoint_addresses ea
                                                     WHERE ea.endpoint_id = d.id AND ea.purpose = 'ip_rule' AND ea.ip = p_ip))
                )
                SELECT id, account_id, type::text, caller_id_number::text, max_outbound::integer, max_total::integer, max_channels, max_cps, add_one
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
