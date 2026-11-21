<?php

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Call records are split by month (migration 000042): cdrs by started_at and
 * cdr_attempts by cdr_started_at (their call's start), so a call and its
 * attempts are always in the same month. Partitions are named
 * cdrs_y2026m11 / cdr_attempts_y2026m11 (UTC months).
 *
 * create() makes the months that are missing, from the oldest record waiting
 * in the default partitions (which catch anything outside the months made so
 * far, so no record is ever refused) through AHEAD months from now. Records in
 * a default partition are moved into their new month in the same transaction.
 * Nothing is ever deleted. Run daily by the scheduler.
 */
final class CdrPartitions
{
    public const AHEAD = 3;

    /** @return int months made */
    public static function create(string $cdrs = 'cdrs', string $attempts = 'cdr_attempts', ?DateTimeImmutable $from = null, int $ahead = self::AHEAD): int
    {
        $utc = new DateTimeZone('UTC');
        $month = fn (DateTimeImmutable $d) => new DateTimeImmutable($d->setTimezone($utc)->format('Y-m-01 00:00:00'), $utc);
        $start = $month(new DateTimeImmutable('now', $utc));
        foreach (array_filter([$from, self::oldestWaiting($cdrs), self::oldestWaiting($attempts, 'cdr_started_at')]) as $d) {
            $start = min($start, $month($d));
        }
        $end = $month(new DateTimeImmutable('now', $utc))->modify("+{$ahead} months");

        return DB::transaction(function () use ($cdrs, $attempts, $start, $end) {
            DB::statement("CREATE TABLE IF NOT EXISTS {$cdrs}_default PARTITION OF {$cdrs} DEFAULT");
            DB::statement("CREATE TABLE IF NOT EXISTS {$attempts}_default PARTITION OF {$attempts} DEFAULT");
            $made = 0;
            for ($m = $start; $m <= $end; $m = $m->modify('+1 month')) {
                $made += self::month($cdrs, $attempts, $m) ? 1 : 0;
            }

            return $made;
        });
    }

    /** Make one month for both tables, moving any of its records out of the default partitions. */
    private static function month(string $cdrs, string $attempts, DateTimeImmutable $m): bool
    {
        $suffix = $m->format('\yY\mm');
        $from = $m->format('Y-m-d H:i:sP');
        $to = $m->modify('+1 month')->format('Y-m-d H:i:sP');
        if (self::exists("{$cdrs}_{$suffix}") && self::exists("{$attempts}_{$suffix}")) {
            return false;
        }
        // The call partition before its attempts' (the attempts reference the calls).
        foreach ([[$cdrs, 'started_at'], [$attempts, 'cdr_started_at']] as [$table, $col]) {
            if (! self::exists("{$table}_{$suffix}")) {
                DB::statement("CREATE TABLE {$table}_{$suffix} (LIKE {$table} INCLUDING DEFAULTS INCLUDING CONSTRAINTS)");
            }
        }
        $moved = [];
        foreach ([[$cdrs, 'started_at'], [$attempts, 'cdr_started_at']] as [$table, $col]) {
            if (self::attached("{$table}_{$suffix}")) {
                continue;
            }
            $moved[$table] = DB::affectingStatement("INSERT INTO {$table}_{$suffix} SELECT * FROM {$table}_default WHERE {$col} >= ? AND {$col} < ?", [$from, $to]);
        }
        // Attempts first, so removing their calls from the default partition cascades to nothing.
        foreach ([[$attempts, 'cdr_started_at'], [$cdrs, 'started_at']] as [$table, $col]) {
            if (isset($moved[$table]) && $moved[$table] > 0) {
                $removed = DB::delete("DELETE FROM {$table}_default WHERE {$col} >= ? AND {$col} < ?", [$from, $to]);
                if ($removed !== $moved[$table]) {
                    throw new RuntimeException("{$table}: moved {$moved[$table]} records into {$suffix} but {$removed} left the default partition.");
                }
            }
        }
        foreach ([[$cdrs, 'started_at'], [$attempts, 'cdr_started_at']] as [$table, $col]) {
            if (! self::attached("{$table}_{$suffix}")) {
                DB::statement("ALTER TABLE {$table} ATTACH PARTITION {$table}_{$suffix} FOR VALUES FROM ('{$from}') TO ('{$to}')");
            }
        }

        return true;
    }

    /** Rename a partitioned table and its partitions (cdrs_new_y2026m11 → cdrs_y2026m11). */
    public static function rename(string $from, string $to): void
    {
        DB::statement("ALTER TABLE {$from} RENAME TO {$to}");
        foreach (DB::select('SELECT c.relname FROM pg_inherits i JOIN pg_class c ON c.oid = i.inhrelid WHERE i.inhparent = to_regclass(?)', [$to]) as $p) {
            if (str_starts_with($p->relname, "{$from}_")) {
                DB::statement(sprintf('ALTER TABLE %s RENAME TO %s', $p->relname, $to.substr($p->relname, strlen($from))));
            }
        }
    }

    /** @return list<array{name: string, rows: int, bytes: int}> each partition of cdrs, oldest first (estimates) */
    public static function list(string $table = 'cdrs'): array
    {
        return array_map(fn ($r) => ['name' => $r->relname, 'rows' => max(0, (int) $r->reltuples), 'bytes' => (int) $r->bytes], DB::select(
            'SELECT c.relname, c.reltuples, pg_total_relation_size(c.oid) AS bytes FROM pg_inherits i JOIN pg_class c ON c.oid = i.inhrelid
             WHERE i.inhparent = to_regclass(?) ORDER BY c.relname', [$table]));
    }

    private static function oldestWaiting(string $table, string $col = 'started_at'): ?DateTimeImmutable
    {
        if (! self::exists("{$table}_default")) {
            return null;
        }
        $min = DB::scalar("SELECT min({$col}) FROM {$table}_default");

        return $min ? new DateTimeImmutable($min) : null;
    }

    private static function exists(string $table): bool
    {
        return DB::scalar('SELECT to_regclass(?) IS NOT NULL', [$table]);
    }

    private static function attached(string $table): bool
    {
        return (bool) DB::scalar('SELECT EXISTS (SELECT 1 FROM pg_inherits WHERE inhrelid = to_regclass(?))', [$table]);
    }
}
