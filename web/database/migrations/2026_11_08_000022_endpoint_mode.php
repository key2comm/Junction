<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * endpoints.mode: a device either has calls sent to it ("sending": IP/FQDN,
 * SIP trunk or a call flow) or sends calls to the switch ("receiving": its
 * rules), not both. A shared PBX is always "sending".
 *
 * Devices with receiving rules and nowhere to send calls become "receiving".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('endpoints', function (Blueprint $table) {
            $table->string('mode', 10)->default('sending')->after('kind');
        });
        DB::update(<<<'SQL'
            UPDATE endpoints e SET mode = 'receiving'
            WHERE e.kind = 'device'
              AND EXISTS (SELECT 1 FROM endpoint_rules r WHERE r.endpoint_id = e.id)
              AND e.sip_username IS NULL
              AND (e.hosts IS NULL OR e.hosts::jsonb = '[]'::jsonb)
              AND COALESCE(e.flow, '') NOT IN ('always', 'pbx')
            SQL);
    }

    public function down(): void
    {
        Schema::table('endpoints', function (Blueprint $table) {
            $table->dropColumn('mode');
        });
    }
};
