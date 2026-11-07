<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds quality_hourly for a time window from vendor attempts. Each hour is
 * recomputed whole (delete + insert in one transaction), so late CDRs and late
 * vendor-leg timings are picked up by simply re-running for recent hours.
 */
class QualityRollup
{
    public function rebuild(CarbonImmutable $from, CarbonImmutable $to): int
    {
        $from = $from->utc()->startOfHour();
        $to = $to->utc()->startOfHour()->addHour();

        return DB::transaction(function () use ($from, $to) {
            DB::table('quality_hourly')->where('hour', '>=', $from)->where('hour', '<', $to)->delete();

            return DB::affectingStatement(<<<'SQL'
                INSERT INTO quality_hourly (hour, vendor_account_id, trunk_id, prefix, attempts, answered, billsec,
                                            pdd_ms_sum, pdd_count, busy, no_answer, congestion, rejected)
                SELECT date_trunc('hour', a.started_at) AS hour,
                       t.account_id, a.trunk_id, coalesce(a.buy_prefix, ''),
                       count(*),
                       count(*) FILTER (WHERE a.answered_at IS NOT NULL),
                       coalesce(sum(c.billsec) FILTER (WHERE a.answered_at IS NOT NULL), 0),
                       coalesce(sum(a.pdd_ms), 0),
                       count(a.pdd_ms),
                       count(*) FILTER (WHERE a.answered_at IS NULL AND a.sip_code IN (486, 600)),
                       count(*) FILTER (WHERE a.answered_at IS NULL AND (a.sip_code IN (480, 408) OR a.hangup_cause IN ('NO_ANSWER', 'NO_USER_RESPONSE'))),
                       count(*) FILTER (WHERE a.answered_at IS NULL AND (a.sip_code BETWEEN 500 AND 599
                                        OR a.hangup_cause IN ('NORMAL_TEMPORARY_FAILURE', 'NORMAL_CIRCUIT_CONGESTION', 'SWITCH_CONGESTION',
                                                              'PROGRESS_TIMEOUT', 'RECOVERY_ON_TIMER_EXPIRE', 'GATEWAY_DOWN', 'DESTINATION_OUT_OF_ORDER'))),
                       count(*) FILTER (WHERE a.answered_at IS NULL AND (a.sip_code BETWEEN 400 AND 699)
                                        AND a.sip_code NOT IN (486, 600, 480, 408) AND a.sip_code NOT BETWEEN 500 AND 599)
                FROM cdr_attempts a
                JOIN cdrs c ON c.id = a.cdr_id
                JOIN trunks t ON t.id = a.trunk_id
                WHERE a.started_at >= ? AND a.started_at < ? AND a.trunk_id IS NOT NULL
                GROUP BY 1, 2, 3, 4
            SQL, [$from, $to]);
        });
    }
}
