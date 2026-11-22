<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Server health alert rules (SystemHealth, Settings → System health), on by default. */
return new class extends Migration
{
    private const RULES = [
        'freeswitch_down' => ['minutes' => 2],
        'disk_space' => ['percent' => 90],
        'queue_stuck' => ['minutes' => 10],
        'cdr_not_saved' => ['minutes' => 10],
        'backup_missed' => ['hours' => 26],
        'certificate_expiring' => ['days' => 14],
        'cdr_months_missing' => [],
    ];

    public function up(): void
    {
        foreach (self::RULES as $type => $params) {
            DB::table('alert_rules')->insertOrIgnore([
                'type' => $type, 'enabled' => true, 'params' => json_encode((object) $params),
                'auto_suspend' => false, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('alert_rules')->whereIn('type', array_keys(self::RULES))->delete();
    }
};
