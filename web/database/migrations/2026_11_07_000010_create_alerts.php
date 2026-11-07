<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fraud and operational alerts.
 *
 * alert_rules: one row per rule type, with editable thresholds (params) and,
 *   for fraud rules, optional automatic suspension of the customer.
 * alerts: one row per problem. While a problem persists the same row is
 *   updated (dedupe key), so it is announced once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_rules', function (Blueprint $table) {
            $table->string('type', 40)->primary();
            $table->boolean('enabled')->default(true);
            $table->jsonb('params');
            $table->boolean('auto_suspend')->default(false);
            $table->timestampsTz();
        });

        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->string('type', 40);
            $table->string('key', 191);                                  // dedupe: one live alert per problem
            $table->string('severity', 16);                              // warning | critical
            $table->string('status', 16)->default('open');               // open | acknowledged | resolved
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('trunk_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('gateway_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->jsonb('details')->nullable();
            $table->string('action_taken')->nullable();                  // e.g. "customer suspended"
            $table->timestampTz('opened_at');
            $table->timestampTz('last_seen_at');
            $table->timestampTz('resolved_at')->nullable();
            $table->unsignedBigInteger('acknowledged_by')->nullable();
            $table->timestampTz('acknowledged_at')->nullable();
            $table->timestampTz('emailed_at')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'opened_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE alerts ADD CONSTRAINT alerts_status CHECK (status IN ('open', 'acknowledged', 'resolved'))");
            DB::statement("ALTER TABLE alerts ADD CONSTRAINT alerts_severity CHECK (severity IN ('warning', 'critical'))");
            DB::statement("CREATE UNIQUE INDEX alerts_one_live_per_key ON alerts (key) WHERE status IN ('open', 'acknowledged')");
        }

        $now = now();
        $rules = [
            'spend_spike' => ['multiplier' => 5, 'min_amount' => 50],
            'new_destinations' => ['count' => 10],
            'high_cost_burst' => ['rate' => 1.00, 'count' => 20],
            'short_call_pattern' => ['count' => 30, 'max_seconds' => 10],
            'vendor_asr_low' => ['asr' => 15, 'min_attempts' => 50],
            'vendor_pdd_high' => ['pdd_ms' => 8000, 'min_attempts' => 30],
            'gateway_failing' => ['attempts' => 20],
        ];
        foreach ($rules as $type => $params) {
            DB::table('alert_rules')->insert(['type' => $type, 'enabled' => true, 'params' => json_encode($params),
                'auto_suspend' => false, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('alerts');
        Schema::dropIfExists('alert_rules');
    }
};
