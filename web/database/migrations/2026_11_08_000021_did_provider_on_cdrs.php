<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * cdrs.did: the DID a call came in on, so the call records can say a call was
 * incoming (also when a device forwarded it out again).
 *
 * Inbound DID calls already stored: their carrier (vendor_account_id) is the
 * DID's provider. New ones get it when the CDR arrives (CdrIngestor).
 * Carrier payables and invoice reconciliation only count calls with a buy
 * cost, which inbound DID calls don't have.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cdrs', function (Blueprint $table) {
            $table->string('did', 32)->nullable()->after('destination');
        });
        DB::update("UPDATE cdrs SET did = destination WHERE direction = 'pbx_in'");

        DB::update(<<<'SQL'
            UPDATE cdrs c SET vendor_account_id = d.provider_account_id
            FROM pbx_dids d
            WHERE c.direction = 'pbx_in' AND c.vendor_account_id IS NULL
              AND d.provider_account_id IS NOT NULL AND d.number = c.destination
        SQL);
    }

    public function down(): void
    {
        Schema::table('cdrs', function (Blueprint $table) {
            $table->dropColumn('did');
        });
    }
};
