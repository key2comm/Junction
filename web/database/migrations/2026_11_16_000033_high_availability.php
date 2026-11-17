<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * High availability: the main server writes a heartbeat every minute; on the
 * backup, its age shows how far behind the copy is. Plus the alert rule for a
 * backup that disconnects or falls behind.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ha_heartbeat', function (Blueprint $table) {
            $table->smallInteger('id')->primary();
            $table->timestampTz('beat_at');
        });

        DB::table('alert_rules')->insertOrIgnore([
            'type' => 'ha_backup', 'enabled' => true, 'params' => json_encode(['max_lag_minutes' => 5]),
            'auto_suspend' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('alert_rules')->where('type', 'ha_backup')->delete();
        Schema::dropIfExists('ha_heartbeat');
    }
};
