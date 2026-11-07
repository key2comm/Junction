<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Refreshes trunk_quality (the last hour per trunk, and per trunk + buy prefix)
 * that quality-aware routing reads. Runs every minute; one small transaction.
 */
class TrunkQuality
{
    public function refresh(int $windowMinutes = 60): int
    {
        return DB::transaction(function () use ($windowMinutes) {
            DB::table('trunk_quality')->delete();

            return DB::affectingStatement(<<<'SQL'
                INSERT INTO trunk_quality (trunk_id, prefix, attempts, answered, pdd_count, pdd_avg, updated_at)
                -- prefix '' = the whole trunk; attempts with no buy prefix get '?' (matches no route).
                SELECT trunk_id, CASE WHEN GROUPING(buy_prefix) = 1 THEN '' ELSE coalesce(buy_prefix, '?') END,
                       count(*), count(answered_at), count(pdd_ms), avg(pdd_ms)::integer, now()
                FROM cdr_attempts
                WHERE started_at >= now() - make_interval(mins => ?) AND trunk_id IS NOT NULL
                GROUP BY GROUPING SETS ((trunk_id, buy_prefix), (trunk_id))
            SQL, [$windowMinutes]);
        });
    }
}
