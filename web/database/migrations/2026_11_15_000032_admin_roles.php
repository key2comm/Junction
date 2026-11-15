<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Admin access levels and invitations (Settings → Admin users).
 * Admins that already exist become owners, so nobody loses access.
 * Portal users (account_id set) have no role.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->nullable()->after('account_id');
        });
        DB::table('users')->whereNull('account_id')->update(['role' => 'owner']);

        Schema::create('admin_invitations', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->string('role', 20);
            $table->string('token_hash', 64)->unique(); // sha256 of the link's token; the token itself is only in the email
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->unique('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_invitations');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('role'));
    }
};
