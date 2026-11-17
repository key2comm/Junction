<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DIDs can be "not assigned" (status unassigned): in stock, not given to a
 * customer yet, so they have no customer and no destination. The call path
 * still finds them (LEFT JOIN), and route.lua rejects the call because the
 * status isn't active.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE pbx_dids ALTER COLUMN account_id DROP NOT NULL');
        DB::statement('ALTER TABLE pbx_dids ALTER COLUMN destination DROP NOT NULL');
        // A DID without a customer must be "not assigned", and the other way round.
        DB::statement("ALTER TABLE pbx_dids ADD CONSTRAINT pbx_dids_unassigned_check CHECK ((status = 'unassigned') = (account_id IS NULL))");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION softswitch_did(p_number text)
            RETURNS TABLE (account_id bigint, number text, dest_type text, dest_target text, status text, language text, account_active boolean,
                           provider_id bigint, provider_max_inbound integer)
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
                SELECT d.account_id, d.number::text, d.destination->>'type', d.destination->>'target',
                       d.status::text, d.language::text, coalesce(a.status = 'active', false),
                       d.provider_account_id,
                       (SELECT t.max_inbound FROM trunks t WHERE t.account_id = d.provider_account_id ORDER BY t.id LIMIT 1)
                FROM pbx_dids d LEFT JOIN accounts a ON a.id = d.account_id
                WHERE d.number = regexp_replace(coalesce(p_number, ''), '\D', '', 'g')
            $$;
            REVOKE ALL ON FUNCTION softswitch_did(text) FROM PUBLIC;
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE pbx_dids DROP CONSTRAINT IF EXISTS pbx_dids_unassigned_check');
        DB::delete("DELETE FROM pbx_dids WHERE status = 'unassigned'");
        DB::statement('ALTER TABLE pbx_dids ALTER COLUMN account_id SET NOT NULL');
        DB::statement('ALTER TABLE pbx_dids ALTER COLUMN destination SET NOT NULL');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION softswitch_did(p_number text)
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
        SQL);
    }
};
