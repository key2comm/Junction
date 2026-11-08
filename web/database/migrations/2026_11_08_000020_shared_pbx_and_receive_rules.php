<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PBXs are shared, devices belong to a customer:
 *
 *  - A PBX (kind = pbx) is an off-switch, often multi-tenant PBX. It belongs to
 *    no customer: DIDs of any customer can be sent to it (hundreds to one PBX).
 *  - A device (kind = device) belongs to a customer and has "receiving" rules
 *    (endpoint_rules) that identify the calls that customer sends us:
 *      domain  the From domain, e.g. tenant.example.com for 100@tenant.example.com
 *      ip      an IP address or hostname the calls come from
 *      number  the caller number exactly as the PBX sends it (the admin adds
 *              each format: +14165551234, 14165551234, 4165551234...)
 *    Domain and number rules only count for calls from a trusted address: a
 *    PBX's address or one of the same device's IP rules (both can be spoofed
 *    by anyone otherwise). Rules are unique switch-wide, so each identifies one
 *    customer. These replace endpoints.outbound_enabled (devices calling out
 *    from their delivery hosts).
 *  - A device can be a SIP trunk for an outside PBX instead of an IP/FQDN:
 *    sip_username / encrypted sip_password, typed into the PBX's trunk. The
 *    PBX registers with the switch (internal profile, digest auth; directory
 *    written by PbxSync), calls to the device are sent to where it registered,
 *    and calls it sends with the login go out through the customer's LCR
 *    (dialplan/device.xml -> device.lua).
 *  - endpoint_addresses now says why an address is known: pbx_host (a PBX's
 *    host: trusted source) or ip_rule (a device's IP rule). Both are let
 *    through the firewall. Rebuilt by EndpointSync.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('endpoint_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('endpoint_id')->constrained()->cascadeOnDelete();
            $table->string('type', 8);            // domain | ip | number
            $table->string('value', 253);
            $table->timestampsTz();

            $table->unique(['type', 'value']);
        });

        // Devices that accepted calls from their delivery hosts keep doing so, as IP rules.
        DB::insert(<<<'SQL'
            INSERT INTO endpoint_rules (endpoint_id, type, value, created_at, updated_at)
            SELECT e.id, 'ip', lower(h), now(), now()
            FROM endpoints e CROSS JOIN LATERAL jsonb_array_elements_text(e.hosts) AS h
            WHERE e.kind = 'device' AND e.outbound_enabled
            ON CONFLICT (type, value) DO NOTHING
        SQL);

        Schema::table('endpoints', function (Blueprint $table) {
            $table->dropColumn('outbound_enabled');
            $table->dropUnique(['account_id', 'name']);
            $table->string('sip_username', 64)->nullable()->unique()->after('hosts');
            $table->text('sip_password')->nullable()->after('sip_username'); // encrypted
        });
        DB::statement('ALTER TABLE endpoints ALTER COLUMN account_id DROP NOT NULL');
        DB::update("UPDATE endpoints SET account_id = NULL, delivery = 'sip', flow = NULL, forward_number = NULL, caller_id_number = NULL WHERE kind = 'pbx'");
        // Device names are unique per customer, PBX names switch-wide.
        DB::statement('CREATE UNIQUE INDEX endpoints_device_name_unique ON endpoints (account_id, name) WHERE account_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX endpoints_pbx_name_unique ON endpoints (name) WHERE account_id IS NULL');

        Schema::drop('endpoint_addresses');
        Schema::create('endpoint_addresses', function (Blueprint $table) {
            $table->foreignId('endpoint_id')->constrained()->cascadeOnDelete();
            $table->string('purpose', 8);          // pbx_host | ip_rule
            $table->ipAddress('ip');
            $table->primary(['endpoint_id', 'purpose', 'ip']);
            $table->index('ip');
        });

        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS softswitch_endpoint(bigint);
            CREATE FUNCTION softswitch_endpoint(p_id bigint)
            RETURNS TABLE (id bigint, account_id bigint, kind text, name text, active boolean, hosts text, transport text, port integer,
                           delivery text, flow text, forward_number text, ring_seconds integer, pbx_type text, pbx_target text,
                           codecs text, caller_id_number text, forward_caller_id text,
                           max_inbound integer, max_total integer, account_max_channels integer, account_max_cps numeric, sip_username text)
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
                SELECT e.id, e.account_id, e.kind::text, e.name::text,
                       e.enabled AND (e.account_id IS NULL OR (a.status = 'active' AND a.is_customer)),
                       (SELECT string_agg(h, ',' ORDER BY n) FROM jsonb_array_elements_text(e.hosts) WITH ORDINALITY AS x(h, n)),
                       e.transport::text, e.port::integer, e.delivery::text, e.flow::text, e.forward_number::text, e.ring_seconds::integer,
                       e.pbx_destination->>'type', e.pbx_destination->>'target',
                       (SELECT string_agg(c, ',' ORDER BY n) FROM jsonb_array_elements_text(e.codecs) WITH ORDINALITY AS x(c, n)),
                       e.caller_id_number::text, e.forward_caller_id::text,
                       e.max_inbound::integer, e.max_total::integer, a.max_channels, a.max_cps, e.sip_username::text
                FROM endpoints e LEFT JOIN accounts a ON a.id = e.account_id
                WHERE e.id = p_id
            $$;

            DROP FUNCTION IF EXISTS softswitch_device(bigint);
            -- A device's SIP trunk login (the directory sets softswitch_device_id
            -- on the channel only after digest auth).
            CREATE FUNCTION softswitch_device(p_id bigint)
            RETURNS TABLE (endpoint_id bigint, account_id bigint, active boolean, caller_id_number text, max_outbound integer,
                           max_total integer, max_channels integer, max_cps numeric)
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
                SELECT e.id, e.account_id, e.enabled AND a.status = 'active' AND a.is_customer, e.caller_id_number::text,
                       e.max_outbound::integer, e.max_total::integer, a.max_channels, a.max_cps
                FROM endpoints e JOIN accounts a ON a.id = e.account_id
                WHERE e.id = p_id AND e.kind = 'device' AND e.sip_username IS NOT NULL
            $$;

            -- Which customer device sent this call (receiving rules): From domain,
            -- then caller number, then source IP. Domain and number rules need
            -- the call to come from a trusted address (a PBX, or the device's own IP rules).
            DROP FUNCTION IF EXISTS softswitch_identify_endpoint(inet);
            CREATE FUNCTION softswitch_identify_endpoint(p_ip inet, p_from_host text, p_caller text)
            RETURNS TABLE (endpoint_id bigint, account_id bigint, matched text, caller_id_number text, max_outbound integer,
                           max_total integer, max_channels integer, max_cps numeric)
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
                WITH devices AS (
                    SELECT e.id, e.account_id, e.caller_id_number, e.max_outbound, e.max_total, a.max_channels, a.max_cps
                    FROM endpoints e JOIN accounts a ON a.id = e.account_id
                    WHERE e.kind = 'device' AND e.enabled AND a.is_customer
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
                SELECT id, account_id, type::text, caller_id_number::text, max_outbound::integer, max_total::integer, max_channels, max_cps
                FROM hits ORDER BY rank, id LIMIT 1
            $$;

            REVOKE ALL ON FUNCTION softswitch_endpoint(bigint) FROM PUBLIC;
            REVOKE ALL ON FUNCTION softswitch_identify_endpoint(inet, text, text) FROM PUBLIC;
            REVOKE ALL ON FUNCTION softswitch_device(bigint) FROM PUBLIC;
        SQL);
    }

    public function down(): void
    {
        // Forward-only: the shared-PBX model can't be mapped back to one customer per PBX.
        throw new RuntimeException('This migration cannot be reversed.');
    }
};
