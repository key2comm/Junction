<?php

use App\Services\CdrPartitions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Call records split by month (PostgreSQL range partitioning on started_at).
 * cdrs and cdr_attempts stay one table each for the app; underneath each month
 * is its own partition (cdrs_y2026m11...), plus a default partition that only
 * catches records outside the months made so far. Nothing is deleted: every
 * record is copied across, the counts are checked, and only then are the old
 * tables dropped, all in one transaction.
 *
 * Because the partition key has to be part of every unique key:
 *  - cdrs: primary key (id, started_at); uuid is unique per start time.
 *  - cdr_attempts: gains cdr_started_at (its call's start, so the attempts sit in
 *    their call's month) and references cdrs (id, started_at).
 * CdrPartitions makes the months ahead (daily, see routes/console.php).
 */
return new class extends Migration
{
    public $withinTransaction = true;

    private const CDR_INDEXES = [
        'CREATE INDEX cdrs_customer_account_id_started_at_index ON cdrs (customer_account_id, started_at)',
        'CREATE INDEX cdrs_direction_started_at_index ON cdrs (direction, started_at)',
        'CREATE INDEX cdrs_started_at_index ON cdrs (started_at)',
        'CREATE INDEX cdrs_vendor_account_id_started_at_index ON cdrs (vendor_account_id, started_at)',
        'CREATE INDEX cdrs_unposted_idx ON cdrs (customer_account_id) WHERE posted_at IS NULL',
        'CREATE INDEX cdrs_vendor_unposted_idx ON cdrs (vendor_account_id) WHERE vendor_posted_at IS NULL',
        'ALTER TABLE cdrs ADD CONSTRAINT cdrs_customer_account_id_foreign FOREIGN KEY (customer_account_id) REFERENCES accounts(id) ON DELETE SET NULL',
        'ALTER TABLE cdrs ADD CONSTRAINT cdrs_vendor_account_id_foreign FOREIGN KEY (vendor_account_id) REFERENCES accounts(id) ON DELETE SET NULL',
        'ALTER TABLE cdrs ADD CONSTRAINT cdrs_trunk_id_foreign FOREIGN KEY (trunk_id) REFERENCES trunks(id) ON DELETE SET NULL',
        'ALTER TABLE cdrs ADD CONSTRAINT cdrs_gateway_id_foreign FOREIGN KEY (gateway_id) REFERENCES gateways(id) ON DELETE SET NULL',
        'ALTER TABLE cdrs ADD CONSTRAINT cdrs_endpoint_id_foreign FOREIGN KEY (endpoint_id) REFERENCES endpoints(id) ON DELETE SET NULL',
    ];

    private const ATTEMPT_INDEXES = [
        'CREATE INDEX cdr_attempts_started_at_idx ON cdr_attempts (started_at)',
        'CREATE INDEX cdr_attempts_cdr_id_index ON cdr_attempts (cdr_id)',
        'ALTER TABLE cdr_attempts ADD CONSTRAINT cdr_attempts_trunk_id_foreign FOREIGN KEY (trunk_id) REFERENCES trunks(id) ON DELETE SET NULL',
        'ALTER TABLE cdr_attempts ADD CONSTRAINT cdr_attempts_gateway_id_foreign FOREIGN KEY (gateway_id) REFERENCES gateways(id) ON DELETE SET NULL',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql' || $this->partitioned('cdrs')) {
            return;
        }
        DB::statement('LOCK TABLE cdrs, cdr_attempts IN ACCESS EXCLUSIVE MODE');
        $cdrs = (int) DB::scalar('SELECT count(*) FROM cdrs');
        $attempts = (int) DB::scalar('SELECT count(*) FROM cdr_attempts');
        $grants = $this->grants();

        // New partitioned tables with the same columns, defaults (the id sequences) and checks.
        DB::statement('CREATE TABLE cdrs_new (LIKE cdrs INCLUDING DEFAULTS INCLUDING CONSTRAINTS) PARTITION BY RANGE (started_at)');
        // Attempts get their call's start (their partition key) as the last column.
        DB::statement('CREATE TABLE cdr_attempts_new (LIKE cdr_attempts INCLUDING DEFAULTS INCLUDING CONSTRAINTS,
            cdr_started_at timestamp(0) with time zone NOT NULL) PARTITION BY RANGE (cdr_started_at)');

        // Every month that has records, through a few months ahead, plus the default partition.
        $first = DB::scalar("SELECT date_trunc('month', min(started_at) AT TIME ZONE 'UTC') FROM cdrs");
        CdrPartitions::create('cdrs_new', 'cdr_attempts_new', $first ? new DateTimeImmutable($first, new DateTimeZone('UTC')) : null);

        DB::statement('INSERT INTO cdrs_new SELECT * FROM cdrs');
        DB::statement('INSERT INTO cdr_attempts_new SELECT a.*, c.started_at FROM cdr_attempts a JOIN cdrs c ON c.id = a.cdr_id');
        $copied = [(int) DB::scalar('SELECT count(*) FROM cdrs_new'), (int) DB::scalar('SELECT count(*) FROM cdr_attempts_new')];
        if ($copied !== [$cdrs, $attempts]) {
            throw new RuntimeException("Call records were not all copied (cdrs {$cdrs} → {$copied[0]}, attempts {$attempts} → {$copied[1]}); nothing was changed.");
        }

        // Swap: keep the id sequences, drop the old tables (their rows are all in the new ones).
        DB::statement('ALTER SEQUENCE cdrs_id_seq OWNED BY NONE');
        DB::statement('ALTER SEQUENCE cdr_attempts_id_seq OWNED BY NONE');
        DB::statement('DROP TABLE cdr_attempts');
        DB::statement('DROP TABLE cdrs');
        CdrPartitions::rename('cdrs_new', 'cdrs');
        CdrPartitions::rename('cdr_attempts_new', 'cdr_attempts');
        DB::statement('ALTER SEQUENCE cdrs_id_seq OWNED BY cdrs.id');
        DB::statement('ALTER SEQUENCE cdr_attempts_id_seq OWNED BY cdr_attempts.id');

        DB::statement('ALTER TABLE cdrs ADD CONSTRAINT cdrs_pkey PRIMARY KEY (id, started_at)');
        DB::statement('ALTER TABLE cdrs ADD CONSTRAINT cdrs_uuid_unique UNIQUE (uuid, started_at)');
        foreach (self::CDR_INDEXES as $sql) {
            DB::statement($sql);
        }
        DB::statement('ALTER TABLE cdr_attempts ADD CONSTRAINT cdr_attempts_pkey PRIMARY KEY (id, cdr_started_at)');
        DB::statement('ALTER TABLE cdr_attempts ADD CONSTRAINT cdr_attempts_cdr_id_attempt_unique UNIQUE (cdr_id, attempt, cdr_started_at)');
        DB::statement('ALTER TABLE cdr_attempts ADD CONSTRAINT cdr_attempts_cdr_id_foreign FOREIGN KEY (cdr_id, cdr_started_at) REFERENCES cdrs (id, started_at) ON DELETE CASCADE ON UPDATE CASCADE');
        foreach (self::ATTEMPT_INDEXES as $sql) {
            DB::statement($sql);
        }
        $this->regrant($grants);
        DB::statement('ANALYZE cdrs');
        DB::statement('ANALYZE cdr_attempts');
    }

    /** Back to plain tables, again copying every record. */
    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! $this->partitioned('cdrs')) {
            return;
        }
        DB::statement('LOCK TABLE cdrs, cdr_attempts IN ACCESS EXCLUSIVE MODE');
        $grants = $this->grants();
        $counts = [(int) DB::scalar('SELECT count(*) FROM cdrs'), (int) DB::scalar('SELECT count(*) FROM cdr_attempts')];
        DB::statement('CREATE TABLE cdrs_old (LIKE cdrs INCLUDING DEFAULTS INCLUDING CONSTRAINTS)');
        DB::statement('CREATE TABLE cdr_attempts_old (LIKE cdr_attempts INCLUDING DEFAULTS INCLUDING CONSTRAINTS)');
        DB::statement('INSERT INTO cdrs_old SELECT * FROM cdrs');
        DB::statement('INSERT INTO cdr_attempts_old SELECT * FROM cdr_attempts');
        if ([(int) DB::scalar('SELECT count(*) FROM cdrs_old'), (int) DB::scalar('SELECT count(*) FROM cdr_attempts_old')] !== $counts) {
            throw new RuntimeException('Call records were not all copied back; nothing was changed.');
        }
        DB::statement('ALTER TABLE cdr_attempts_old DROP COLUMN cdr_started_at');
        DB::statement('ALTER SEQUENCE cdrs_id_seq OWNED BY NONE');
        DB::statement('ALTER SEQUENCE cdr_attempts_id_seq OWNED BY NONE');
        DB::statement('DROP TABLE cdr_attempts');
        DB::statement('DROP TABLE cdrs');
        DB::statement('ALTER TABLE cdrs_old RENAME TO cdrs');
        DB::statement('ALTER TABLE cdr_attempts_old RENAME TO cdr_attempts');
        DB::statement('ALTER SEQUENCE cdrs_id_seq OWNED BY cdrs.id');
        DB::statement('ALTER SEQUENCE cdr_attempts_id_seq OWNED BY cdr_attempts.id');
        DB::statement('ALTER TABLE cdrs ADD CONSTRAINT cdrs_pkey PRIMARY KEY (id)');
        DB::statement('ALTER TABLE cdrs ADD CONSTRAINT cdrs_uuid_unique UNIQUE (uuid)');
        foreach (self::CDR_INDEXES as $sql) {
            DB::statement($sql);
        }
        DB::statement('ALTER TABLE cdr_attempts ADD CONSTRAINT cdr_attempts_pkey PRIMARY KEY (id)');
        DB::statement('ALTER TABLE cdr_attempts ADD CONSTRAINT cdr_attempts_cdr_id_attempt_unique UNIQUE (cdr_id, attempt)');
        DB::statement('ALTER TABLE cdr_attempts ADD CONSTRAINT cdr_attempts_cdr_id_foreign FOREIGN KEY (cdr_id) REFERENCES cdrs (id) ON DELETE CASCADE');
        foreach (array_filter(self::ATTEMPT_INDEXES, fn ($s) => ! str_contains($s, 'cdr_attempts_cdr_id_index')) as $sql) {
            DB::statement($sql);
        }
        $this->regrant($grants);
    }

    private function partitioned(string $table): bool
    {
        return (bool) DB::scalar("SELECT EXISTS (SELECT 1 FROM pg_partitioned_table WHERE partrelid = to_regclass(?))", [$table]);
    }

    /** @return list<array{0: string, 1: string, 2: string}> table, role, privilege (other than the owner's) */
    private function grants(): array
    {
        return array_map(fn ($r) => [$r->table_name, $r->grantee, $r->privilege_type], DB::select(
            "SELECT table_name, grantee, privilege_type FROM information_schema.role_table_grants
             WHERE table_schema = 'public' AND table_name IN ('cdrs', 'cdr_attempts') AND grantee <> current_user"));
    }

    private function regrant(array $grants): void
    {
        foreach ($grants as [$table, $role, $privilege]) {
            DB::statement(sprintf('GRANT %s ON %s TO %s', $privilege, $table, '"'.str_replace('"', '""', $role).'"'));
        }
    }
};
