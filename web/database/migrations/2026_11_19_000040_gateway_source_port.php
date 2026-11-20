<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * gateways.source_port: which of the switch's SIP ports a carrier SIP trunk
 * sends calls from. "carrier" (the carrier port, 5080 by default) or "pbx"
 * (the PBX port, 5060), for carriers that only take calls from 5060.
 * App\Services\Freeswitch\GatewaySync puts the gateway in that profile.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gateways', function (Blueprint $table) {
            $table->string('source_port', 8)->default('carrier')->after('transport');
        });
        DB::statement("ALTER TABLE gateways ADD CONSTRAINT gateways_source_port CHECK (source_port IN ('carrier', 'pbx'))");
    }

    public function down(): void
    {
        Schema::table('gateways', fn (Blueprint $t) => $t->dropColumn('source_port'));
    }
};
