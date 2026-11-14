<?php

use App\Support\TaxRegions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Billing → Taxes: sales taxes per Canadian province / US state, charged on
 * plan items and call usage as they're posted (ledger type "tax").
 *
 *   taxes          rates per region ("CA-ON" HST 13%), pre-filled for Canada,
 *                  every US state listed at 0% to fill in
 *   tax_postings   each tax amount posted, with the amount it was on
 *   accounts.tax_region  where a customer is taxed (none: no tax)
 *   invoice_taxes  tax totals on an invoice
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('taxes', function (Blueprint $table) {
            $table->id();
            $table->string('region', 8);
            $table->string('name', 64);
            $table->decimal('rate', 7, 4);                     // percent
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestampsTz();
            $table->unique(['region', 'name']);
        });
        DB::statement('ALTER TABLE taxes ADD CONSTRAINT taxes_rate CHECK (rate >= 0 AND rate <= 100)');

        $now = now();
        $rows = [];
        foreach (TaxRegions::CANADA as $code => $name) {
            foreach (TaxRegions::CANADA_TAXES[$code] ?? [] as $i => [$tax, $rate]) {
                $rows[] = ['region' => "CA-{$code}", 'name' => $tax, 'rate' => $rate, 'position' => $i, 'created_at' => $now, 'updated_at' => $now];
            }
        }
        foreach (TaxRegions::USA as $code => $name) {
            $rows[] = ['region' => "US-{$code}", 'name' => 'Sales tax', 'rate' => '0', 'position' => 0, 'created_at' => $now, 'updated_at' => $now];
        }
        DB::table('taxes')->insert($rows);

        Schema::table('accounts', function (Blueprint $table) {
            $table->string('tax_region', 8)->nullable();
        });

        Schema::create('tax_postings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tax_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 64);
            $table->decimal('rate', 7, 4);
            $table->string('source', 16);                     // usage | charge
            $table->decimal('base', 16, 6);                   // what it was charged on (credits negative)
            $table->decimal('amount', 16, 6);                 // tax charged (credits negative)
            $table->foreignId('ledger_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampTz('effective_at');
            $table->timestampsTz();
            $table->index(['account_id', 'effective_at']);
        });

        DB::statement('ALTER TABLE ledger_entries DROP CONSTRAINT ledger_entries_type');
        DB::statement("ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_type CHECK (type IN ('payment', 'usage', 'adjustment', 'charge', 'tax'))");

        Schema::table('invoices', function (Blueprint $table) {
            $table->decimal('taxes_total', 16, 6)->default(0);
            $table->string('tax_region', 8)->nullable();
            $table->text('tax_numbers')->nullable();           // the issuer's registration numbers, as printed
        });
        Schema::create('invoice_taxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('name', 64);
            $table->decimal('rate', 7, 4);
            $table->decimal('base', 16, 6);
            $table->decimal('amount', 16, 6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_taxes');
        Schema::table('invoices', fn (Blueprint $t) => $t->dropColumn(['taxes_total', 'tax_region', 'tax_numbers']));
        DB::statement("DELETE FROM ledger_entries WHERE type = 'tax'");
        DB::statement('ALTER TABLE ledger_entries DROP CONSTRAINT ledger_entries_type');
        DB::statement("ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_type CHECK (type IN ('payment', 'usage', 'adjustment', 'charge'))");
        Schema::dropIfExists('tax_postings');
        Schema::table('accounts', fn (Blueprint $t) => $t->dropColumn('tax_region'));
        Schema::dropIfExists('taxes');
    }
};
