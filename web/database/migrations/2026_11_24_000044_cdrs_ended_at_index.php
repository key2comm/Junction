<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index on when calls ended: the Grafana quality metrics count the calls that
 * finished in the last 15 minutes (a call's record, and its carrier attempts,
 * only arrive when it ends). On the partitioned table the index is made on
 * every month's partition, and on the months made later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cdrs', fn (Blueprint $table) => $table->index('ended_at'));
    }

    public function down(): void
    {
        Schema::table('cdrs', fn (Blueprint $table) => $table->dropIndex(['ended_at']));
    }
};
