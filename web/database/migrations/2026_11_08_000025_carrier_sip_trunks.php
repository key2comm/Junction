<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Carriers own their settings and SIP trunks directly (no separate "Trunks"
 * step in the UI). Internally each carrier keeps one `trunks` row (its routing
 * record: carrier rates, limits, codecs, number format) and its SIP trunks are
 * `gateways` rows.
 *
 *   trunks.max_inbound   concurrent calls the carrier may send us (DIDs)
 *   trunks.max_channels  concurrent calls we may send it (outbound)
 *   trunks.codecs        codecs offered on every outbound call to it, in order
 *   gateways.label       what the SIP trunk is for ("Primary", "Failover"...)
 *   gateways.from_user / from_domain / outbound_proxy / send_rpid / send_pai
 *
 * softswitch_did() also returns the DID's provider and its inbound limit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trunks', function (Blueprint $table) {
            $table->integer('max_inbound')->nullable()->after('max_channels');
            $table->json('codecs')->nullable()->after('max_inbound');
        });
        DB::update("UPDATE trunks SET codecs = '[\"PCMU\",\"PCMA\"]'");
        // The carrier's routing record is shown under the carrier's own name.
        DB::update(<<<'SQL'
            UPDATE trunks t SET name = a.name FROM accounts a
            WHERE a.id = t.account_id AND t.id = (SELECT min(id) FROM trunks x WHERE x.account_id = t.account_id)
              AND NOT EXISTS (SELECT 1 FROM trunks y WHERE y.account_id = t.account_id AND y.id <> t.id AND y.name = a.name)
        SQL);

        Schema::table('gateways', function (Blueprint $table) {
            $table->string('label', 100)->nullable()->after('name');
            $table->string('from_user', 128)->nullable()->after('password');
            $table->string('from_domain', 255)->nullable()->after('from_user');
            $table->string('outbound_proxy', 255)->nullable()->after('from_domain');
            $table->boolean('send_rpid')->default(true)->after('outbound_proxy');
            $table->boolean('send_pai')->default(false)->after('send_rpid');
        });
        DB::update('UPDATE gateways SET label = name');

        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS softswitch_did(text);
            CREATE FUNCTION softswitch_did(p_number text)
            RETURNS TABLE (account_id bigint, number text, dest_type text, dest_target text, status text, language text, account_active boolean,
                           provider_id bigint, provider_max_inbound integer)
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
                SELECT d.account_id, d.number::text, d.destination->>'type', d.destination->>'target',
                       d.status::text, d.language::text, a.status = 'active',
                       d.provider_account_id,
                       (SELECT t.max_inbound FROM trunks t WHERE t.account_id = d.provider_account_id ORDER BY t.id LIMIT 1)
                FROM pbx_dids d JOIN accounts a ON a.id = d.account_id
                WHERE d.number = regexp_replace(coalesce(p_number, ''), '\D', '', 'g')
            $$;
            REVOKE ALL ON FUNCTION softswitch_did(text) FROM PUBLIC;

            -- What lcr.lua needs per SIP trunk beyond softswitch_route(): caller ID headers and the carrier's codecs.
            CREATE OR REPLACE FUNCTION softswitch_gateway_options(p_ids bigint[])
            RETURNS TABLE (gateway_id bigint, send_rpid boolean, send_pai boolean, cid_host text, codecs text)
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
                SELECT g.id, g.send_rpid, g.send_pai, coalesce(nullif(g.from_domain, ''), g.host)::text,
                       (SELECT string_agg(c, ',' ORDER BY n) FROM json_array_elements_text(t.codecs) WITH ORDINALITY AS x(c, n))
                FROM gateways g JOIN trunks t ON t.id = g.trunk_id
                WHERE g.id = ANY (p_ids)
            $$;
            REVOKE ALL ON FUNCTION softswitch_gateway_options(bigint[]) FROM PUBLIC;
        SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('This migration cannot be reversed.');
    }
};
