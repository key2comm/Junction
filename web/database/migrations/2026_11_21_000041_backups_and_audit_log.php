<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Settings → Backups: one row per database backup (App\Services\BackupRunner).
 * Settings → Audit log: who changed what (App\Support\Audit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backups', function (Blueprint $table) {
            $table->id();
            $table->string('filename', 200)->nullable();
            $table->string('path', 300)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->boolean('encrypted')->default(false);
            $table->string('status', 10)->default('running'); // running | done | failed
            $table->string('trigger', 10)->default('schedule'); // schedule | manual
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('emailed_at')->nullable();
            $table->timestamp('sftp_at')->nullable();
            $table->timestamp('s3_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->index('started_at');
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->timestamp('created_at')->useCurrent();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name', 255)->nullable();
            $table->string('user_email', 255)->nullable();
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete(); // portal users' customer
            $table->string('via', 10); // web | portal | api
            $table->string('event', 20); // change | login | logout | login_failed
            $table->string('action', 150)->nullable(); // route name
            $table->string('method', 10)->nullable();
            $table->string('path', 500)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('subject_type', 60)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_label', 255)->nullable();
            $table->jsonb('changes')->nullable();
            $table->jsonb('input')->nullable();
            $table->index('created_at');
            $table->index(['user_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('backups');
    }
};
