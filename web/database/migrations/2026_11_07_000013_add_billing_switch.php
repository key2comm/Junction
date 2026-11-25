<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Settings → Billing can turn the built-in billing platform off, for switches
 * that only route calls while another system bills the customers.
 *
 * softswitch_billing_enabled(): the app_settings flag (on unless set to false).
 * softswitch_route(): unchanged, except that with billing off there is no funds
 *   check (balances aren't kept up to date then): calls get the full maximum
 *   duration and reserve nothing. Sell rates are still required, and vendors
 *   costing more than the sell rate are still skipped.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION softswitch_billing_enabled() RETURNS boolean
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
                SELECT coalesce((SELECT value FROM app_settings WHERE key = 'billing.enabled') IS DISTINCT FROM 'false'::jsonb, true)
            $$;
            REVOKE ALL ON FUNCTION softswitch_billing_enabled() FROM PUBLIC;


            -- softswitch_route() is defined in database/sql/softswitch_route.sql (applied after every migrate).
        SQL);
    }

    public function down(): void
    {
        // With the setting absent (or on) the new function behaves exactly like the
        // previous one, so it is left in place.
    }
};
