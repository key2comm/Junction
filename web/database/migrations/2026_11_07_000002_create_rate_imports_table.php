<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rate_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rate_deck_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('filename');
            $table->string('path');
            $table->string('mode', 16);                              // update | replace
            $table->timestampTz('effective_from');
            $table->string('status', 16)->default('queued');         // queued | running | done | failed
            $table->jsonb('stats')->nullable();                      // added, changed, unchanged, ended, increases_within_notice, rows
            $table->jsonb('errors')->nullable();                     // first N problems, e.g. "Line 12: ..."
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rate_imports');
    }
};
