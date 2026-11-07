<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Vendor quality.
 *
 * cdr_leg_timings: exact timings of each vendor attempt (B-leg), from
 *   FreeSWITCH's per-leg CDRs. B-legs often finish before the customer leg, so
 *   timings that arrive before their parent CDR wait here and are merged into
 *   cdr_attempts when it arrives.
 * quality_hourly: per hour, trunk and destination prefix: attempts, answers,
 *   billed seconds, PDD and failure classes. Rebuilt for recent hours by
 *   softswitch:quality-rollup; ASR/ACD/PDD are derived from these sums.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cdr_leg_timings', function (Blueprint $table) {
            $table->uuid('parent_uuid');
            $table->unsignedSmallInteger('attempt');
            $table->timestampTz('started_at', 6)->nullable();
            $table->timestampTz('progress_at', 6)->nullable();
            $table->timestampTz('answered_at', 6)->nullable();
            $table->timestampTz('ended_at', 6)->nullable();
            $table->unsignedInteger('pdd_ms')->nullable();
            $table->unsignedSmallInteger('sip_code')->nullable();
            $table->string('hangup_cause', 64)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->primary(['parent_uuid', 'attempt']);
        });

        Schema::create('quality_hourly', function (Blueprint $table) {
            $table->timestampTz('hour');
            $table->foreignId('vendor_account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('trunk_id')->constrained()->cascadeOnDelete();
            $table->string('prefix', 32)->default('');
            $table->unsignedInteger('attempts');
            $table->unsignedInteger('answered');
            $table->unsignedBigInteger('billsec');
            $table->unsignedBigInteger('pdd_ms_sum');
            $table->unsignedInteger('pdd_count');
            $table->unsignedInteger('busy');          // 486, 600
            $table->unsignedInteger('no_answer');     // 480, 408, NO_ANSWER
            $table->unsignedInteger('congestion');    // 5xx, congestion, timeouts to progress
            $table->unsignedInteger('rejected');      // 403, 404, 484, 488, 603 and other 4xx/6xx

            $table->primary(['hour', 'trunk_id', 'prefix']);
            $table->index(['vendor_account_id', 'hour']);
        });

        // Attempt-level timing precision (was whole seconds).
        DB::statement('ALTER TABLE cdr_attempts ALTER COLUMN started_at TYPE timestamptz(6), ALTER COLUMN answered_at TYPE timestamptz(6), ALTER COLUMN ended_at TYPE timestamptz(6)');
        DB::statement('CREATE INDEX cdr_attempts_started_at_idx ON cdr_attempts (started_at)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS cdr_attempts_started_at_idx');
        Schema::dropIfExists('quality_hourly');
        Schema::dropIfExists('cdr_leg_timings');
    }
};
