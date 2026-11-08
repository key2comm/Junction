<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Settings -> Firewall: the admin's whitelist (always allowed, never banned)
 * and blacklist (always dropped). A value is an IP, a CIDR network or a
 * hostname; hostnames are resolved when the firewall is synced.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('firewall_entries', function (Blueprint $table) {
            $table->id();
            $table->string('list', 8);                 // allow | block
            $table->string('value', 255);              // IP, CIDR or hostname
            $table->string('reason', 255)->nullable();
            $table->timestampTz('expires_at')->nullable(); // blacklist only; null = permanent
            $table->jsonb('resolved')->nullable();     // hostname -> its IPs at the last sync
            $table->timestampTz('resolved_at')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['list', 'value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('firewall_entries');
    }
};
