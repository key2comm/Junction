<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REST API tokens. Only a SHA-256 hash of each token is stored; the plaintext
 * is shown once when created. A token scoped to an account (customer tokens,
 * for the portal and customer integrations) can only see that account's data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->char('token_hash', 64)->unique();
            $table->string('token_prefix', 12);                     // first characters, to recognise a token
            $table->jsonb('abilities');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();      // who created it
            $table->foreignId('account_id')->nullable()->constrained()->cascadeOnDelete(); // customer scope
            $table->timestampTz('last_used_at')->nullable();
            $table->ipAddress('last_used_ip')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_tokens');
    }
};
