<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Settings → Scheduled reports. A schedule makes a call records (CDR) CSV for
 * each day, week or month that ends (in its time zone), keeps it on the server
 * for retention_days, and can also email it and upload it by SFTP. Each file
 * made is a report_files row (one per schedule and period).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->boolean('enabled')->default(true);
            $table->string('frequency', 8);                      // daily | weekly | monthly
            $table->string('timezone', 64)->default('UTC');      // where days/weeks/months start
            $table->jsonb('filters')->default('{}');             // CdrFilters values: customer, vendor, direction, status
            $table->unsignedSmallInteger('retention_days')->default(90);
            $table->text('email_to')->nullable();                // comma separated
            $table->boolean('sftp_enabled')->default(false);
            $table->string('sftp_host', 255)->nullable();
            $table->unsignedInteger('sftp_port')->default(22);
            $table->string('sftp_username', 128)->nullable();
            $table->text('sftp_password')->nullable();           // encrypted
            $table->text('sftp_private_key')->nullable();        // encrypted
            $table->string('sftp_path', 255)->nullable();        // remote directory
            $table->string('sftp_fingerprint', 128)->nullable(); // optional host key check
            $table->timestampTz('last_run_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestampsTz();
        });

        Schema::create('report_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_schedule_id')->constrained()->cascadeOnDelete();
            $table->timestampTz('period_start');
            $table->timestampTz('period_end');
            $table->string('path', 255);
            $table->string('filename', 255);
            $table->unsignedBigInteger('size')->default(0);
            $table->unsignedInteger('rows')->default(0);
            $table->timestampTz('emailed_at')->nullable();
            $table->timestampTz('uploaded_at')->nullable();
            $table->text('error')->nullable();                   // email/SFTP problems (the file itself is fine)
            $table->timestampsTz();
            $table->unique(['report_schedule_id', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_files');
        Schema::dropIfExists('report_schedules');
    }
};
