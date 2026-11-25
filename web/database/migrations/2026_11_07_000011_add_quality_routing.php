<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quality-aware routing.
 *
 * trunk_quality: last hour's attempts/answers/PDD per trunk (prefix '') and per
 *   trunk + buy prefix, refreshed every minute (App\Services\TrunkQuality) so the
 *   routing function reads a small indexed table instead of raw attempts.
 * routing_plans.quality_*: per-plan switch and thresholds. When on, trunks
 *   below the bar are tried after healthy ones (never dropped).
 * softswitch_route(): same as before plus that ordering, and a new last column
 *   quality_demoted so the route tester can show why.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trunk_quality', function (Blueprint $table) {
            $table->foreignId('trunk_id')->constrained()->cascadeOnDelete();
            $table->string('prefix', 32)->default('');
            $table->unsignedInteger('attempts');
            $table->unsignedInteger('answered');
            $table->unsignedInteger('pdd_count');
            $table->unsignedInteger('pdd_avg')->nullable();
            $table->timestampTz('updated_at')->useCurrent();

            $table->primary(['trunk_id', 'prefix']);
        });

        Schema::table('routing_plans', function (Blueprint $table) {
            $table->boolean('quality_routing')->default(false)->after('max_attempts');
            $table->decimal('quality_min_asr', 5, 2)->default(10)->after('quality_routing');
            $table->unsignedInteger('quality_max_pdd_ms')->default(10000)->after('quality_min_asr');
            $table->unsignedInteger('quality_min_attempts')->default(30)->after('quality_max_pdd_ms');
        });

        DB::unprepared(<<<'SQL'
            ALTER TYPE softswitch_route_row ADD ATTRIBUTE quality_demoted boolean;

            -- softswitch_route() is defined in database/sql/softswitch_route.sql (applied after every migrate).
        SQL);
    }

    public function down(): void
    {
        Schema::table('routing_plans', fn (Blueprint $table) => $table->dropColumn(['quality_routing', 'quality_min_asr', 'quality_max_pdd_ms', 'quality_min_attempts']));
        Schema::dropIfExists('trunk_quality');
    }
};
