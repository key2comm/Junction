<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tariffs can be built from Carrier Rates plus a markup (App\Services\TariffBuilder).
 * The tariff remembers its source Carrier Rates and markup so it can be rebuilt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rate_decks', function (Blueprint $table) {
            $table->string('markup_type', 8)->nullable()->after('description');  // percent | fixed (added per minute)
            $table->decimal('markup_value', 12, 6)->nullable()->after('markup_type');
            $table->timestampTz('built_at')->nullable()->after('markup_value');
        });
        DB::statement("ALTER TABLE rate_decks ADD CONSTRAINT rate_decks_markup_type_check CHECK (markup_type IS NULL OR markup_type IN ('percent', 'fixed'))");

        Schema::create('tariff_sources', function (Blueprint $table) {
            $table->foreignId('tariff_id')->constrained('rate_decks')->cascadeOnDelete();
            $table->foreignId('carrier_rate_deck_id')->constrained('rate_decks')->cascadeOnDelete();
            $table->primary(['tariff_id', 'carrier_rate_deck_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tariff_sources');
        Schema::table('rate_decks', fn (Blueprint $table) => $table->dropColumn(['markup_type', 'markup_value', 'built_at']));
    }
};
