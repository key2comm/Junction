<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Blocked numbers per customer (Customers → a customer → Blocked numbers):
 * exact numbers the customer may not call, or receive calls from on its DIDs.
 *
 *   account_blocked_numbers   number (digits; 10-digit Canada/USA numbers get the 1),
 *                             direction both | outbound | inbound, reason
 *
 * softswitch_number_blocked(account, number, direction): true when the number
 *   is blocked for that direction. Used by softswitch_route() (outbound:
 *   devices, PBX extensions and forwards all route through it) and by route.lua
 *   for calls arriving on the customer's DIDs (inbound, by caller number).
 * softswitch_route(): as in 000038, plus that check before the blocked
 *   prefixes (reason blocked_number, SIP 403).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_blocked_numbers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('number', 20);
            $table->string('direction', 8)->default('both');
            $table->string('reason')->nullable();
            $table->timestamps();
            $table->unique(['account_id', 'number']);
        });
        DB::statement("ALTER TABLE account_blocked_numbers ADD CONSTRAINT account_blocked_numbers_number CHECK (number ~ '^[0-9]{3,20}$')");
        DB::statement("ALTER TABLE account_blocked_numbers ADD CONSTRAINT account_blocked_numbers_direction CHECK (direction IN ('both', 'outbound', 'inbound'))");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION softswitch_number_blocked(p_account_id bigint, p_number text, p_direction text) RETURNS boolean
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
                SELECT EXISTS (
                    SELECT 1 FROM account_blocked_numbers b,
                         LATERAL (SELECT regexp_replace(coalesce(p_number, ''), '\D', '', 'g') AS d) n
                    WHERE b.account_id = p_account_id
                      AND b.number = CASE WHEN n.d ~ '^[2-9][0-9]{2}[2-9][0-9]{6}$' THEN '1' || n.d ELSE n.d END
                      AND b.direction IN ('both', p_direction)
                )
            $$;
            REVOKE ALL ON FUNCTION softswitch_number_blocked(bigint, text, text) FROM PUBLIC;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS softswitch_number_blocked(bigint, text, text)');
        Schema::dropIfExists('account_blocked_numbers');
    }

};
