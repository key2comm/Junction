<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Routing -> PBX/Devices ("endpoints"): where a customer's calls are delivered.
 *
 * endpoints: a customer's PBX (one host) or device (up to three hosts, e.g.
 *   Twilio's), each with transport, port, codecs, caller ID and call limits.
 *   delivery = 'sip' rings the hosts; 'flow' applies a call flow:
 *     always    forward straight to forward_number
 *     no_answer / busy / failed   ring the hosts first, then forward
 *     pbx       a destination on this switch's PBX (pbx_destination)
 *   Forwards go out through the customer's LCR and are billed like their other
 *   outbound calls. forward_caller_id: 'original' passes the caller's number
 *   on; 'endpoint' shows caller_id_number instead.
 * endpoint_addresses: the hosts' IPs (hostnames resolved by the switch sync),
 *   so calls an endpoint sends us (outbound_enabled) are identified as that
 *   customer's, and the firewall lets them in.
 *
 * DIDs (pbx_dids.destination) can point at one: {"type": "endpoint", "target": "<id>"}.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('endpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 8);                              // pbx | device
            $table->string('name');
            $table->boolean('enabled')->default(true);
            $table->jsonb('hosts')->default('[]');                  // ["pbx.example.com"] (device: up to 3)
            $table->string('transport', 3)->default('udp');         // udp | tcp | tls
            $table->unsignedInteger('port')->default(5060);
            $table->string('delivery', 4)->default('sip');          // sip | flow
            $table->string('flow', 10)->nullable();                 // always | no_answer | busy | failed | pbx
            $table->string('forward_number', 15)->nullable();
            $table->unsignedSmallInteger('ring_seconds')->default(25);
            $table->jsonb('pbx_destination')->nullable();
            $table->jsonb('codecs')->default('["PCMU","PCMA"]');    // FreeSWITCH codec names, in order of preference
            $table->string('caller_id_number', 32)->nullable();
            $table->string('forward_caller_id', 8)->default('original'); // original | endpoint
            $table->boolean('outbound_enabled')->default(true);    // accept calls it sends us
            $table->unsignedInteger('max_inbound')->nullable();
            $table->unsignedInteger('max_outbound')->nullable();
            $table->unsignedInteger('max_total')->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->unique(['account_id', 'name']);
        });

        Schema::create('endpoint_addresses', function (Blueprint $table) {
            $table->foreignId('endpoint_id')->constrained()->cascadeOnDelete();
            $table->ipAddress('ip');
            $table->primary(['endpoint_id', 'ip']);
        });
        DB::statement('ALTER TABLE endpoint_addresses ALTER COLUMN ip TYPE inet USING ip::inet');

        Schema::table('cdrs', function (Blueprint $table) {
            $table->foreignId('endpoint_id')->nullable()->after('customer_account_id')->constrained()->nullOnDelete();
        });

        DB::unprepared(<<<'SQL'
            -- migrate:fresh drops tables, not functions; later migrations change these.
            DROP FUNCTION IF EXISTS softswitch_endpoint(bigint);
            DROP FUNCTION IF EXISTS softswitch_identify_endpoint(inet);
            DROP FUNCTION IF EXISTS softswitch_identify_endpoint(inet, text, text);
            DROP FUNCTION IF EXISTS softswitch_device(bigint);

            -- Everything the call path needs to deliver a call to an endpoint.
            CREATE OR REPLACE FUNCTION softswitch_endpoint(p_id bigint)
            RETURNS TABLE (id bigint, account_id bigint, kind text, name text, active boolean, hosts text, transport text, port integer,
                           delivery text, flow text, forward_number text, ring_seconds integer, pbx_type text, pbx_target text,
                           codecs text, caller_id_number text, forward_caller_id text,
                           max_inbound integer, max_total integer, account_max_channels integer, account_max_cps numeric)
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
                SELECT e.id, e.account_id, e.kind::text, e.name::text,
                       e.enabled AND a.status = 'active' AND a.is_customer,
                       (SELECT string_agg(h, ',' ORDER BY n) FROM jsonb_array_elements_text(e.hosts) WITH ORDINALITY AS x(h, n)),
                       e.transport::text, e.port::integer, e.delivery::text, e.flow::text, e.forward_number::text, e.ring_seconds::integer,
                       e.pbx_destination->>'type', e.pbx_destination->>'target',
                       (SELECT string_agg(c, ',' ORDER BY n) FROM jsonb_array_elements_text(e.codecs) WITH ORDINALITY AS x(c, n)),
                       e.caller_id_number::text, e.forward_caller_id::text,
                       e.max_inbound::integer, e.max_total::integer, a.max_channels, a.max_cps
                FROM endpoints e JOIN accounts a ON a.id = e.account_id
                WHERE e.id = p_id
            $$;

            -- A call sent to us by an endpoint (customer PBX/device) that may call out.
            CREATE OR REPLACE FUNCTION softswitch_identify_endpoint(p_ip inet)
            RETURNS TABLE (endpoint_id bigint, account_id bigint, caller_id_number text, max_outbound integer, max_total integer,
                           max_channels integer, max_cps numeric)
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
                SELECT e.id, e.account_id, e.caller_id_number::text, e.max_outbound::integer, e.max_total::integer, a.max_channels, a.max_cps
                FROM endpoint_addresses ea
                JOIN endpoints e ON e.id = ea.endpoint_id
                JOIN accounts a ON a.id = e.account_id
                WHERE ea.ip = p_ip AND e.enabled AND e.outbound_enabled AND a.is_customer
                ORDER BY e.id
                LIMIT 1
            $$;

            REVOKE ALL ON FUNCTION softswitch_endpoint(bigint) FROM PUBLIC;
            REVOKE ALL ON FUNCTION softswitch_identify_endpoint(inet) FROM PUBLIC;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS softswitch_identify_endpoint(inet);
            DROP FUNCTION IF EXISTS softswitch_endpoint(bigint);
        SQL);
        Schema::table('cdrs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('endpoint_id');
        });
        Schema::dropIfExists('endpoint_addresses');
        Schema::dropIfExists('endpoints');
    }
};
