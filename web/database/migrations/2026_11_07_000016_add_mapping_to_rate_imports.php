<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rate imports in two steps: the uploaded file is read first (status
 * "mapping", with a preview of its header and first rows), the admin matches
 * file columns to fields (Prefix, NPA, NXX, Interval, Rate, Date effective...),
 * then the import runs with that mapping.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rate_imports', function (Blueprint $table) {
            $table->jsonb('preview')->nullable()->after('errors');   // header, first rows, suggested columns
            $table->jsonb('mapping')->nullable()->after('preview');  // has_header, columns {field: index}, npa_country
        });
    }

    public function down(): void
    {
        Schema::table('rate_imports', fn (Blueprint $table) => $table->dropColumn(['preview', 'mapping']));
    }
};
