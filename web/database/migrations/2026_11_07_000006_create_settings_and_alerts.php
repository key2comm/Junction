<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * app_settings: settings edited in the admin UI (e.g. SMTP). Secret values
 * are stored encrypted by App\Support\Settings.
 *
 * Low-balance alerts: per-customer threshold, and when the current alert was
 * sent (cleared once funds recover, so customers get one email per dip).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_settings', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->jsonb('value')->nullable();
            $table->timestampsTz();
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->decimal('low_balance_threshold', 14, 4)->nullable()->after('credit_limit');
            $table->timestampTz('low_balance_alerted_at')->nullable()->after('low_balance_threshold');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->timestampTz('emailed_at')->nullable()->after('voided_at');
            $table->string('emailed_to')->nullable()->after('emailed_at');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn(['emailed_at', 'emailed_to']));
        Schema::table('accounts', fn (Blueprint $table) => $table->dropColumn(['low_balance_threshold', 'low_balance_alerted_at']));
        Schema::dropIfExists('app_settings');
    }
};
