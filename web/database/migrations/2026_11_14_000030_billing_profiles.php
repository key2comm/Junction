<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Billing → Billing items and Billing profiles.
 *
 *   billing_items          the shared catalog ("Unlimited hosting, per ext, monthly")
 *   billing_profiles       a named plan: items + how Canada/USA calls are charged
 *     └─< billing_profile_items
 *   account_charges        what's on one customer's bill (from their profile, or extra)
 *   billing_charges        every charge posted to a ledger (monthly, prorated, one-time, change)
 *   account_bundle_usage   included Canada/USA seconds used, per account per month
 *
 * Calls covered by a profile's plan (unlimited, or within the included minutes)
 * are rated at zero and counted in cdrs.included_seconds; the routing function
 * doesn't hold funds back for them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_items', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('description')->nullable();
            $table->string('unit', 32)->default('each');          // "per ext", "per DID"...
            $table->decimal('price', 12, 6);
            $table->string('frequency', 16)->default('monthly');  // monthly | once
            $table->boolean('active')->default(true);
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE billing_items ADD CONSTRAINT billing_items_frequency CHECK (frequency IN ('monthly', 'once'))");
        DB::statement('ALTER TABLE billing_items ADD CONSTRAINT billing_items_price CHECK (price >= 0)');

        Schema::create('billing_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('description')->nullable();
            $table->string('nanp_plan', 16)->default('metered');  // metered | unlimited | bundle
            $table->unsignedInteger('nanp_minutes')->nullable();   // bundle: included minutes a month
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE billing_profiles ADD CONSTRAINT billing_profiles_plan CHECK (nanp_plan IN ('metered', 'unlimited', 'bundle'))");
        DB::statement("ALTER TABLE billing_profiles ADD CONSTRAINT billing_profiles_minutes CHECK (nanp_plan <> 'bundle' OR nanp_minutes > 0)");

        Schema::create('billing_profile_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('billing_item_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('price', 12, 6)->nullable();           // null: the item's price
            $table->timestampsTz();
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->foreignId('billing_profile_id')->nullable()->constrained()->nullOnDelete();
        });

        Schema::create('account_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('billing_item_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('price', 12, 6)->nullable();           // null: the item's price
            $table->boolean('from_profile')->default(false);
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE account_charges ADD CONSTRAINT account_charges_quantity CHECK (quantity > 0)');

        Schema::create('billing_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_charge_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 16);                            // monthly | prorated | once | change
            $table->char('period', 7)->nullable();                 // YYYY-MM it pays for (monthly, prorated)
            $table->string('description');
            $table->decimal('quantity', 10, 2);
            $table->decimal('unit_price', 12, 6);
            $table->decimal('amount', 16, 6);                      // charged: positive; credit: negative
            $table->foreignId('ledger_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampTz('effective_at');
            $table->timestampsTz();
            $table->index(['account_id', 'effective_at']);
        });
        DB::statement("ALTER TABLE billing_charges ADD CONSTRAINT billing_charges_kind CHECK (kind IN ('monthly', 'prorated', 'once', 'change'))");
        // A line is charged for a month once (in full, or prorated when it started mid-month).
        DB::statement("CREATE UNIQUE INDEX billing_charges_once_a_month ON billing_charges (account_charge_id, period) WHERE kind IN ('monthly', 'prorated')");

        Schema::create('account_bundle_usage', function (Blueprint $table) {
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->char('period', 7);
            $table->unsignedBigInteger('seconds')->default(0);
            $table->primary(['account_id', 'period']);
        });

        Schema::table('cdrs', function (Blueprint $table) {
            $table->unsignedInteger('included_seconds')->default(0);
        });

        DB::statement('ALTER TABLE ledger_entries DROP CONSTRAINT ledger_entries_type');
        DB::statement("ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_type CHECK (type IN ('payment', 'usage', 'adjustment', 'charge'))");

        Schema::table('invoices', function (Blueprint $table) {
            $table->decimal('charges_total', 16, 6)->default(0);   // plan items (positive number; credits lower it)
            $table->unsignedBigInteger('included_seconds')->default(0);
        });
        Schema::create('invoice_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('description');
            $table->decimal('quantity', 10, 2);
            $table->decimal('unit_price', 12, 6);
            $table->decimal('amount', 16, 6);
        });

        DB::unprepared(self::routeFunction(included: true));
    }

    public function down(): void
    {
        DB::unprepared(self::routeFunction(included: false));
        Schema::dropIfExists('invoice_charges');
        Schema::table('invoices', fn (Blueprint $t) => $t->dropColumn(['charges_total', 'included_seconds']));
        DB::statement("DELETE FROM ledger_entries WHERE type = 'charge'");
        DB::statement('ALTER TABLE ledger_entries DROP CONSTRAINT ledger_entries_type');
        DB::statement("ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_type CHECK (type IN ('payment', 'usage', 'adjustment'))");
        Schema::table('cdrs', fn (Blueprint $t) => $t->dropColumn('included_seconds'));
        Schema::dropIfExists('account_bundle_usage');
        Schema::dropIfExists('billing_charges');
        Schema::dropIfExists('account_charges');
        Schema::table('accounts', fn (Blueprint $t) => $t->dropConstrainedForeignId('billing_profile_id'));
        Schema::dropIfExists('billing_profile_items');
        Schema::dropIfExists('billing_profiles');
        Schema::dropIfExists('billing_items');
    }

    /**
     * softswitch_route as the dialing-rules migration (000029) wrote it, and
     * with $included: calls the customer's plan covers (unlimited Canada/USA,
     * or within the month's included minutes) need no funds and reserve none.
     */
    private static function routeFunction(bool $included): string
    {
        $source = file_get_contents(database_path('migrations/2026_11_10_000029_dialing_rules.php'));
        $start = strpos($source, 'CREATE OR REPLACE FUNCTION softswitch_route(');
        $endMarker = 'REVOKE ALL ON FUNCTION softswitch_route(bigint, text, uuid, timestamptz, integer) FROM PUBLIC;';
        $end = strpos($source, $endMarker, (int) $start);
        if ($start === false || $end === false) {
            throw new RuntimeException('softswitch_route not found in migration 000029');
        }
        $sql = substr($source, $start, $end - $start + strlen($endMarker));
        if (! $included) {
            return $sql;
        }

        $patch = function (string $from, string $to) use (&$sql) {
            if (substr_count($sql, $from) !== 1) {
                throw new RuntimeException("softswitch_route patch point not found once: {$from}");
            }
            $sql = str_replace($from, $to, $sql);
        };
        $patch(
            "                dest_country text;\n            BEGIN",
            "                dest_country text;\n                prof billing_profiles%ROWTYPE;\n                covered boolean := false;\n                used bigint;\n            BEGIN",
        );
        $patch(
            "                -- Funds (only when the built-in billing is on).",
            <<<'PLPGSQL'
                -- Billing profile: Canada/USA calls inside the plan (unlimited, or
                -- while included minutes remain this month) cost nothing up front.
                IF acct.billing_profile_id IS NOT NULL THEN
                    SELECT * INTO prof FROM billing_profiles WHERE id = acct.billing_profile_id;
                    IF FOUND AND prof.nanp_plan <> 'metered' AND coalesce(dest_country, softswitch_dial_country(num)) = 'NANP' THEN
                        IF prof.nanp_plan = 'unlimited' THEN
                            covered := true;
                        ELSE
                            SELECT u.seconds INTO used FROM account_bundle_usage u
                            WHERE u.account_id = acct.id AND u.period = to_char(p_at AT TIME ZONE acct.timezone, 'YYYY-MM');
                            covered := coalesce(used, 0) < prof.nanp_minutes::bigint * 60;
                        END IF;
                    END IF;
                END IF;

                -- Funds (only when the built-in billing is on).
PLPGSQL,
        );
        $patch("                IF NOT billing THEN\n                    maxdur := p_max_duration;", "                IF NOT billing OR covered THEN\n                    maxdur := p_max_duration;");
        $patch('CASE WHEN billing THEN sell.connect_fee', 'CASE WHEN billing AND NOT covered THEN sell.connect_fee');

        return $sql;
    }
};
