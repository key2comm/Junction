<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-call SIP captures (Calls -> Call records -> PCAP). sip_call_id is the
 * caller leg's Call-ID; the legs the switch places use "<uuid>-<n>..." Call-IDs
 * (util.lua leg_call_id), so PcapExtractor can find every leg of a call in the
 * raw capture. pcap_path is the per-call file on the private disk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cdrs', function (Blueprint $table) {
            $table->string('sip_call_id', 255)->nullable()->after('uuid');
            $table->string('pcap_path', 255)->nullable()->after('sip_call_id');
        });
    }

    public function down(): void
    {
        Schema::table('cdrs', function (Blueprint $table) {
            $table->dropColumn(['sip_call_id', 'pcap_path']);
        });
    }
};
