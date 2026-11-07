<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rate decks are split into two kinds: tariffs (what customers pay, chosen on
 * customer accounts) and carrier rates (what carriers charge us, chosen on
 * carrier trunks). Existing decks used by a customer become tariffs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rate_decks', function (Blueprint $table) {
            $table->string('kind', 10)->default('carrier')->after('name');
            $table->index('kind');
        });
        DB::statement("UPDATE rate_decks SET kind = 'tariff' WHERE id IN (SELECT sell_rate_deck_id FROM accounts WHERE sell_rate_deck_id IS NOT NULL)");
        DB::statement("ALTER TABLE rate_decks ADD CONSTRAINT rate_decks_kind_check CHECK (kind IN ('tariff', 'carrier'))");
    }

    public function down(): void
    {
        Schema::table('rate_decks', fn (Blueprint $table) => $table->dropColumn('kind'));
    }
};
