<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Invoicing.
 *
 * ledger_entries.effective_at: when the money movement applies (for usage, when
 * the calls happened), as opposed to created_at (when it was recorded). Usage is
 * posted in batches that never cross an hour, dated by their calls, so a
 * statement period's ledger usage equals its call records exactly.
 *
 * invoices are statements derived from the ledger and CDRs; they never move
 * money themselves, so voiding and re-issuing is always safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Existing entries take their recorded time; the append-only trigger is
        // paused only for this one-time backfill.
        DB::statement('ALTER TABLE ledger_entries ADD COLUMN effective_at timestamptz');
        DB::statement('ALTER TABLE ledger_entries DISABLE TRIGGER ledger_entries_no_update_delete');
        DB::statement('UPDATE ledger_entries SET effective_at = created_at');
        DB::statement('ALTER TABLE ledger_entries ENABLE TRIGGER ledger_entries_no_update_delete');
        DB::statement('ALTER TABLE ledger_entries ALTER COLUMN effective_at SET DEFAULT now(), ALTER COLUMN effective_at SET NOT NULL');
        DB::statement('CREATE INDEX ledger_entries_account_effective_idx ON ledger_entries (account_id, effective_at)');

        Schema::table('cdrs', function (Blueprint $table) {
            // Destination name at rating time, for invoice lines (rate decks change).
            $table->string('sell_destination')->nullable()->after('sell_prefix');
        });

        DB::statement('CREATE SEQUENCE IF NOT EXISTS invoice_number_seq');

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->string('status', 16)->default('issued');          // issued | void
            $table->timestampTz('period_start');
            $table->timestampTz('period_end');
            $table->string('timezone', 64);
            $table->char('currency', 3);
            // Snapshot of the account when issued.
            $table->string('account_name');
            $table->string('account_code', 32);
            $table->string('billing_email')->nullable();
            $table->string('billing_type', 16);
            // Statement figures (positive = in the customer's favour, as on the ledger).
            $table->decimal('opening_balance', 16, 6);
            $table->decimal('usage_total', 16, 6);                    // charges for calls (positive number)
            $table->decimal('payments_total', 16, 6);
            $table->decimal('adjustments_total', 16, 6);
            $table->decimal('closing_balance', 16, 6);
            $table->decimal('amount_due', 16, 6);                     // postpaid: what to pay; prepaid: 0
            $table->unsignedInteger('calls')->default(0);
            $table->unsignedBigInteger('billsec')->default(0);
            $table->timestampTz('issued_at');
            $table->timestampTz('due_at')->nullable();
            $table->string('void_reason')->nullable();
            $table->timestampTz('voided_at')->nullable();
            $table->timestampsTz();

            $table->index(['account_id', 'period_start']);
        });

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('prefix', 32)->nullable();
            $table->string('destination')->nullable();
            $table->unsignedInteger('calls');
            $table->unsignedBigInteger('billsec');
            $table->decimal('rate', 12, 6)->nullable();               // per-minute sell rate (highest seen)
            $table->decimal('amount', 16, 6);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_status CHECK (status IN ('issued', 'void'))");
            DB::statement('ALTER TABLE invoices ADD CONSTRAINT invoices_period_order CHECK (period_end > period_start)');
            // One live invoice per account and period.
            DB::statement("CREATE UNIQUE INDEX invoices_one_issued_per_period ON invoices (account_id, period_start) WHERE status = 'issued'");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
        DB::statement('DROP SEQUENCE IF EXISTS invoice_number_seq');
        Schema::table('cdrs', fn (Blueprint $table) => $table->dropColumn('sell_destination'));
        DB::statement('DROP INDEX IF EXISTS ledger_entries_account_effective_idx');
        DB::statement('ALTER TABLE ledger_entries DROP COLUMN IF EXISTS effective_at');
    }
};
