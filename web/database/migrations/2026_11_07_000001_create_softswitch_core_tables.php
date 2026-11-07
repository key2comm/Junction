<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Core Class 4 data model.
 *
 *   rate_decks ─< rates                    price lists (per-minute, by prefix, effective-dated)
 *   routing_plans ─< routing_plan_routes   which trunks may carry which prefixes
 *   accounts                               companies: customer and/or vendor
 *     ├─< account_ips                      customer ingress auth (source IP/CIDR + tech prefix)
 *     └─< trunks ─< gateways               vendor egress products and their SIP endpoints
 *   blocked_prefixes                       global or per-customer destination blocks
 *   cdrs ─< cdr_attempts                   one CDR per customer call, one attempt per vendor try
 *   ledger_entries                         append-only money movements (balance history)
 *
 * The FreeSWITCH routing engine reads these tables directly; Laravel owns writes.
 * PostgreSQL only (inet, CHECK constraints, triggers).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rate_decks', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->char('currency', 3)->default('USD');
            $table->text('description')->nullable();
            $table->timestampsTz();
        });

        Schema::create('rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rate_deck_id')->constrained()->cascadeOnDelete();
            $table->string('prefix', 32);
            $table->string('destination')->nullable();
            $table->decimal('rate', 12, 6);                       // per minute
            $table->decimal('connect_fee', 12, 6)->default(0);
            $table->unsignedSmallInteger('initial_increment')->default(60); // seconds, e.g. 60 of 60/6
            $table->unsignedSmallInteger('increment')->default(60);        // seconds, e.g. 6 of 60/6
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_to')->nullable();
            $table->timestampsTz();

            $table->index(['rate_deck_id', 'prefix', 'effective_from']);
        });

        Schema::create('routing_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('mode', 16)->default('lcr');              // lcr | priority | weight
            $table->unsignedSmallInteger('max_attempts')->default(3);
            $table->text('description')->nullable();
            $table->timestampsTz();
        });

        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 32)->unique();                    // short reference for CDRs/invoices
            $table->boolean('is_customer')->default(false);
            $table->boolean('is_vendor')->default(false);
            $table->string('status', 16)->default('active');         // active | suspended | closed
            $table->char('currency', 3)->default('USD');
            $table->string('billing_type', 16)->default('prepaid');  // prepaid | postpaid
            $table->decimal('credit_limit', 14, 4)->default(0);
            $table->decimal('balance', 16, 6)->default(0);           // maintained by the ledger only
            $table->string('timezone', 64)->default('UTC');
            $table->string('billing_email')->nullable();
            $table->string('noc_email')->nullable();
            // Customer settings
            $table->foreignId('sell_rate_deck_id')->nullable()->constrained('rate_decks')->nullOnDelete();
            $table->foreignId('routing_plan_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('max_channels')->nullable();
            $table->decimal('max_cps', 8, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();
        });

        Schema::create('account_ips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->ipAddress('ip');                                 // inet: host or CIDR
            $table->string('tech_prefix', 16)->default('');
            $table->string('description')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestampsTz();

            $table->unique(['ip', 'tech_prefix']);
        });

        Schema::create('trunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->restrictOnDelete(); // vendor
            $table->string('name');
            $table->foreignId('rate_deck_id')->nullable()->constrained()->nullOnDelete(); // buy rates
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('max_channels')->nullable();
            $table->decimal('max_cps', 8, 2)->nullable();
            $table->string('tech_prefix', 16)->default('');          // prepended to the dialled number
            $table->unsignedSmallInteger('strip_digits')->default(0);
            $table->timestampsTz();

            $table->unique(['account_id', 'name']);
        });

        Schema::create('gateways', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trunk_id')->constrained()->cascadeOnDelete();
            $table->string('name', 64)->unique();                    // FreeSWITCH gateway name
            $table->string('host');
            $table->unsignedInteger('port')->default(5060);
            $table->string('transport', 8)->default('udp');          // udp | tcp | tls
            $table->string('username')->nullable();
            $table->text('password')->nullable();                    // encrypted by the model
            $table->boolean('register')->default(false);
            $table->boolean('enabled')->default(true);
            $table->unsignedSmallInteger('priority')->default(1);
            $table->unsignedSmallInteger('weight')->default(100);
            $table->timestampsTz();
        });

        Schema::create('routing_plan_routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('routing_plan_id')->constrained()->cascadeOnDelete();
            $table->string('prefix', 32)->default('');               // '' = every destination
            $table->foreignId('trunk_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('priority')->default(1);
            $table->unsignedSmallInteger('weight')->default(100);
            $table->boolean('enabled')->default(true);
            $table->timestampsTz();

            $table->unique(['routing_plan_id', 'prefix', 'trunk_id']);
        });

        Schema::create('blocked_prefixes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->nullable()->constrained()->cascadeOnDelete(); // null = global
            $table->string('prefix', 32);
            $table->string('reason')->nullable();
            $table->timestampsTz();
        });

        Schema::create('cdrs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('customer_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->ipAddress('source_ip')->nullable();
            $table->string('caller', 64)->nullable();
            $table->string('callee', 64);                            // as received
            $table->string('destination', 32)->nullable();           // normalised E.164 digits
            $table->timestampTz('started_at');
            $table->timestampTz('answered_at')->nullable();
            $table->timestampTz('ended_at')->nullable();
            $table->unsignedInteger('billsec')->default(0);
            $table->unsignedInteger('pdd_ms')->nullable();
            $table->unsignedSmallInteger('sip_code')->nullable();
            $table->string('hangup_cause', 64)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            // Sell side (customer)
            $table->string('sell_prefix', 32)->nullable();
            $table->decimal('sell_rate', 12, 6)->nullable();
            $table->decimal('sell_cost', 14, 6)->nullable();
            // Buy side (winning vendor attempt)
            $table->foreignId('vendor_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('trunk_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('gateway_id')->nullable()->constrained()->nullOnDelete();
            $table->string('buy_prefix', 32)->nullable();
            $table->decimal('buy_rate', 12, 6)->nullable();
            $table->decimal('buy_cost', 14, 6)->nullable();
            $table->timestampTz('rated_at')->nullable();
            $table->timestampTz('posted_at')->nullable();            // included in a ledger usage entry
            $table->timestampsTz();

            $table->index('started_at');
            $table->index(['customer_account_id', 'started_at']);
            $table->index(['vendor_account_id', 'started_at']);
        });

        Schema::create('cdr_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cdr_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('attempt');
            $table->foreignId('trunk_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('gateway_id')->nullable()->constrained()->nullOnDelete();
            $table->string('buy_prefix', 32)->nullable();
            $table->decimal('buy_rate', 12, 6)->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('answered_at')->nullable();
            $table->timestampTz('ended_at')->nullable();
            $table->unsignedSmallInteger('sip_code')->nullable();
            $table->string('hangup_cause', 64)->nullable();
            $table->unsignedInteger('pdd_ms')->nullable();

            $table->unique(['cdr_id', 'attempt']);
        });

        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->string('type', 16);                              // payment | usage | adjustment
            $table->decimal('amount', 16, 6);                        // + credits the account, - debits it
            $table->decimal('balance_after', 16, 6);
            $table->char('currency', 3);
            $table->string('description');
            $table->nullableMorphs('reference');
            $table->unsignedBigInteger('user_id')->nullable();       // no FK: entries outlive users
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['account_id', 'id']);
        });

        $this->addPostgresConstraints();
    }

    private function addPostgresConstraints(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $checks = [
            'rates' => [
                "rates_prefix_digits CHECK (prefix ~ '^[0-9]{1,32}$')",
                'rates_rate_nonnegative CHECK (rate >= 0 AND connect_fee >= 0)',
                'rates_increments_positive CHECK (initial_increment > 0 AND increment > 0)',
                'rates_effective_order CHECK (effective_to IS NULL OR effective_to > effective_from)',
            ],
            'routing_plans' => [
                "routing_plans_mode CHECK (mode IN ('lcr', 'priority', 'weight'))",
                'routing_plans_max_attempts CHECK (max_attempts BETWEEN 1 AND 10)',
            ],
            'accounts' => [
                'accounts_has_role CHECK (is_customer OR is_vendor)',
                "accounts_status CHECK (status IN ('active', 'suspended', 'closed'))",
                "accounts_billing_type CHECK (billing_type IN ('prepaid', 'postpaid'))",
                "accounts_code_format CHECK (code ~ '^[A-Za-z0-9_-]+$')",
                'accounts_credit_limit_nonnegative CHECK (credit_limit >= 0)',
            ],
            'account_ips' => ["account_ips_tech_prefix CHECK (tech_prefix ~ '^[0-9#*]{0,16}$')"],
            'trunks' => ["trunks_tech_prefix CHECK (tech_prefix ~ '^[0-9#*]{0,16}$')"],
            'gateways' => [
                "gateways_name_format CHECK (name ~ '^[a-z0-9][a-z0-9_-]*$')",
                "gateways_transport CHECK (transport IN ('udp', 'tcp', 'tls'))",
                'gateways_port_range CHECK (port BETWEEN 1 AND 65535)',
            ],
            'routing_plan_routes' => ["routing_plan_routes_prefix_digits CHECK (prefix ~ '^[0-9]{0,32}$')"],
            'blocked_prefixes' => ["blocked_prefixes_prefix_digits CHECK (prefix ~ '^[0-9]{1,32}$')"],
            'ledger_entries' => ["ledger_entries_type CHECK (type IN ('payment', 'usage', 'adjustment'))"],
        ];
        foreach ($checks as $table => $constraints) {
            foreach ($constraints as $constraint) {
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$constraint}");
            }
        }

        // A prefix may be blocked once globally and once per account.
        DB::statement('CREATE UNIQUE INDEX blocked_prefixes_account_prefix_unique ON blocked_prefixes (account_id, prefix) NULLS NOT DISTINCT');

        // Unrated / unposted CDRs are what the billing jobs scan for.
        DB::statement('CREATE INDEX cdrs_unposted_idx ON cdrs (customer_account_id) WHERE posted_at IS NULL');

        // The ledger is append-only: corrections are new entries, never edits.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION ledger_entries_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'ledger_entries is append-only (attempted %)', TG_OP;
            END;
            $$;
            CREATE TRIGGER ledger_entries_no_update_delete
                BEFORE UPDATE OR DELETE ON ledger_entries
                FOR EACH ROW EXECUTE FUNCTION ledger_entries_immutable();
            CREATE TRIGGER ledger_entries_no_truncate
                BEFORE TRUNCATE ON ledger_entries
                FOR EACH STATEMENT EXECUTE FUNCTION ledger_entries_immutable();
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS ledger_entries_no_truncate ON ledger_entries');
            DB::unprepared('DROP TRIGGER IF EXISTS ledger_entries_no_update_delete ON ledger_entries');
            DB::unprepared('DROP FUNCTION IF EXISTS ledger_entries_immutable()');
        }

        foreach (['ledger_entries', 'cdr_attempts', 'cdrs', 'blocked_prefixes', 'routing_plan_routes',
            'gateways', 'trunks', 'account_ips', 'accounts', 'routing_plans', 'rates', 'rate_decks'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
