<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Routing -> DIDs: each DID records its provider (the carrier the number
 * comes from), a two-letter language, and a status:
 *   active       calls are delivered to its destination
 *   deactivated  calls are refused (404)
 *   banned       calls are refused (403), e.g. the customer hasn't paid;
 *                status_reason says why
 * ("enabled" becomes the status; description is shown as the comment.)
 *
 * softswitch_pbx_did() is replaced by softswitch_did(), which also returns
 * DIDs that aren't active so the call path can refuse them with the right
 * reason instead of treating them as unknown traffic.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pbx_dids', function (Blueprint $table) {
            $table->foreignId('provider_account_id')->nullable()->after('account_id')->constrained('accounts')->nullOnDelete();
            $table->string('language', 2)->default('en')->after('destination');
            $table->string('status', 12)->default('active')->after('language');
            $table->string('status_reason', 255)->nullable()->after('status');
        });
        DB::update("UPDATE pbx_dids SET status = 'deactivated' WHERE NOT enabled");
        Schema::table('pbx_dids', function (Blueprint $table) {
            $table->dropColumn('enabled');
            $table->index('status');
        });

        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS softswitch_pbx_did(text);

            -- A DID delivered by a carrier (any format: only its digits count).
            CREATE OR REPLACE FUNCTION softswitch_did(p_number text)
            RETURNS TABLE (account_id bigint, number text, dest_type text, dest_target text, status text, language text, account_active boolean)
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
                SELECT d.account_id, d.number::text, d.destination->>'type', d.destination->>'target',
                       d.status::text, d.language::text, a.status = 'active'
                FROM pbx_dids d JOIN accounts a ON a.id = d.account_id
                WHERE d.number = regexp_replace(coalesce(p_number, ''), '\D', '', 'g')
            $$;

            REVOKE ALL ON FUNCTION softswitch_did(text) FROM PUBLIC;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS softswitch_did(text);');
        Schema::table('pbx_dids', function (Blueprint $table) {
            $table->boolean('enabled')->default(true);
        });
        DB::update("UPDATE pbx_dids SET enabled = (status = 'active')");
        Schema::table('pbx_dids', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropConstrainedForeignId('provider_account_id');
            $table->dropColumn(['language', 'status', 'status_reason']);
        });
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION softswitch_pbx_did(p_number text)
            RETURNS TABLE (account_id bigint, number text, dest_type text, dest_target text)
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
                SELECT d.account_id, d.number::text, d.destination->>'type', d.destination->>'target'
                FROM pbx_dids d JOIN accounts a ON a.id = d.account_id
                WHERE d.number = regexp_replace(coalesce(p_number, ''), '\D', '', 'g')
                  AND d.enabled AND a.status = 'active'
            $$;
            REVOKE ALL ON FUNCTION softswitch_pbx_did(text) FROM PUBLIC;
        SQL);
    }
};
