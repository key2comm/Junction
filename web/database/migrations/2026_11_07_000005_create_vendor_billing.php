<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Vendor billing (payables).
 *
 *   vendor_ledger_entries   append-only record of what we owe each vendor:
 *                           usage (+, from our CDRs' buy cost), payments we make (-),
 *                           adjustments (±, e.g. accepting a vendor invoice difference)
 *   accounts.vendor_balance what we currently owe the vendor (maintained by the ledger)
 *   vendor_invoices         invoices received from vendors, compared with our CDRs
 *   vendor_invoice_lines    the vendor's per-destination breakdown, if supplied
 *
 * Kept apart from the customer ledger: an account can be both a customer and a
 * vendor, and the two balances mean different things.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->decimal('vendor_balance', 16, 6)->default(0)->after('balance');
        });

        Schema::table('cdrs', function (Blueprint $table) {
            $table->timestampTz('vendor_posted_at')->nullable()->after('posted_at');
        });
        DB::statement('CREATE INDEX cdrs_vendor_unposted_idx ON cdrs (vendor_account_id) WHERE vendor_posted_at IS NULL');

        Schema::create('vendor_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->string('type', 16);                         // usage | payment | adjustment
            $table->decimal('amount', 16, 6);                   // + we owe more, - we owe less
            $table->decimal('balance_after', 16, 6);            // what we owe after this entry
            $table->char('currency', 3);
            $table->string('description');
            $table->nullableMorphs('reference');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestampTz('effective_at')->useCurrent();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['account_id', 'effective_at']);
        });

        Schema::create('vendor_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->string('reference', 64);                    // the vendor's invoice number
            $table->timestampTz('period_start');
            $table->timestampTz('period_end');
            $table->string('timezone', 64)->default('UTC');
            $table->char('currency', 3);
            // Their figures
            $table->decimal('amount', 16, 6);
            $table->unsignedInteger('calls')->nullable();
            $table->decimal('minutes', 14, 2)->nullable();
            // Ours, from CDRs, at the time of entry (refreshable)
            $table->decimal('our_amount', 16, 6);
            $table->unsignedInteger('our_calls');
            $table->decimal('our_minutes', 14, 2);
            $table->string('status', 16)->default('open');      // open | accepted | disputed
            $table->text('notes')->nullable();
            $table->string('filename')->nullable();             // their breakdown CSV, if uploaded
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();

            $table->unique(['account_id', 'reference']);
        });

        Schema::create('vendor_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_invoice_id')->constrained()->cascadeOnDelete();
            $table->string('prefix', 32);
            $table->string('destination')->nullable();
            $table->unsignedInteger('calls')->nullable();
            $table->decimal('minutes', 14, 2)->nullable();
            $table->decimal('amount', 16, 6);

            $table->unique(['vendor_invoice_id', 'prefix']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE vendor_ledger_entries ADD CONSTRAINT vendor_ledger_entries_type CHECK (type IN ('usage', 'payment', 'adjustment'))");
            DB::statement("ALTER TABLE vendor_invoices ADD CONSTRAINT vendor_invoices_status CHECK (status IN ('open', 'accepted', 'disputed'))");
            DB::statement('ALTER TABLE vendor_invoices ADD CONSTRAINT vendor_invoices_period_order CHECK (period_end > period_start)');
            DB::statement("ALTER TABLE vendor_invoice_lines ADD CONSTRAINT vendor_invoice_lines_prefix CHECK (prefix ~ '^[0-9]{1,32}$')");
            // Same append-only guarantee as the customer ledger.
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER vendor_ledger_entries_no_update_delete
                    BEFORE UPDATE OR DELETE ON vendor_ledger_entries
                    FOR EACH ROW EXECUTE FUNCTION ledger_entries_immutable();
                CREATE TRIGGER vendor_ledger_entries_no_truncate
                    BEFORE TRUNCATE ON vendor_ledger_entries
                    FOR EACH STATEMENT EXECUTE FUNCTION ledger_entries_immutable();
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_invoice_lines');
        Schema::dropIfExists('vendor_invoices');
        Schema::dropIfExists('vendor_ledger_entries');
        DB::statement('DROP INDEX IF EXISTS cdrs_vendor_unposted_idx');
        Schema::table('cdrs', fn (Blueprint $table) => $table->dropColumn('vendor_posted_at'));
        Schema::table('accounts', fn (Blueprint $table) => $table->dropColumn('vendor_balance'));
    }
};
