<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ApiAuth;
use App\Services\SystemHealth;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/v1/metrics: the server's figures in Prometheus' text format, for
 * Grafana (through Prometheus, Grafana Alloy or Grafana Cloud). Needs an API
 * token with the metrics:read ability. CPU, memory, disk, network and
 * FreeSWITCH come from SystemHealth's 15-second readings; active calls,
 * alerts and call quality (ASR/ACD/PDD per carrier and customer) are read live.
 */
class MetricsController extends Controller
{
    /** @var array<string, list<string>> metric => its lines (Prometheus wants each metric's values together) */
    private array $metrics = [];

    public function __invoke(Request $request): Response
    {
        ApiAuth::requireAdmin($request);
        ['fast' => $fast, 'slow' => $slow] = SystemHealth::readings();
        $host = ['host' => (string) (config('softswitch.hostname') ?: gethostname())];

        $this->metric('junction_info', 'gauge', 'Junction version and host.', 1, $host + ['version' => (string) config('softswitch.version')]);

        if ($fast) {
            $this->metric('junction_sample_age_seconds', 'gauge', 'Age of the server readings (taken every 15 seconds).',
                (int) CarbonImmutable::parse($fast['at'])->diffInSeconds(now(), true));
            if ($fast['cpu'] !== null) {
                $this->metric('junction_cpu_usage_percent', 'gauge', 'CPU in use, all cores (0-100).', $fast['cpu']);
            }
            $this->metric('junction_cpu_cores', 'gauge', 'CPU cores.', $fast['cores']);
            foreach (['1' => 0, '5' => 1, '15' => 2] as $minutes => $i) {
                $this->metric('junction_load_average', 'gauge', 'Load average.', $fast['load'][$i], ['minutes' => (string) $minutes]);
            }
            $this->metric('junction_memory_total_bytes', 'gauge', 'Memory.', $fast['memory']['total']);
            $this->metric('junction_memory_used_bytes', 'gauge', 'Memory in use (not counting cache).', $fast['memory']['used']);
            $this->metric('junction_swap_total_bytes', 'gauge', 'Swap.', $fast['swap']['total']);
            $this->metric('junction_swap_used_bytes', 'gauge', 'Swap in use.', $fast['swap']['used']);
            $this->metric('junction_disk_total_bytes', 'gauge', 'Disk.', $fast['disk']['total']);
            $this->metric('junction_disk_used_bytes', 'gauge', 'Disk in use.', $fast['disk']['used']);
            foreach ($fast['network'] as $if => $n) {
                $this->metric('junction_network_receive_bytes_total', 'counter', 'Bytes received since boot.', $n['rx'], ['interface' => $if]);
                $this->metric('junction_network_transmit_bytes_total', 'counter', 'Bytes sent since boot.', $n['tx'], ['interface' => $if]);
                if ($n['rx_bps'] !== null) {
                    $this->metric('junction_network_receive_bits_per_second', 'gauge', 'Receive rate over the last 15 seconds.', $n['rx_bps'], ['interface' => $if]);
                    $this->metric('junction_network_transmit_bits_per_second', 'gauge', 'Send rate over the last 15 seconds.', $n['tx_bps'], ['interface' => $if]);
                }
            }

            $fs = $fast['freeswitch'];
            $this->metric('junction_freeswitch_up', 'gauge', 'FreeSWITCH answering (1) or not (0).', $fs['up'] ? 1 : 0);
            if ($fs['up']) {
                $this->metric('junction_freeswitch_uptime_seconds', 'gauge', 'FreeSWITCH uptime.', $fs['uptime']);
                $this->metric('junction_freeswitch_sessions', 'gauge', 'Active call legs (a call through the switch is two).', $fs['sessions']);
                $this->metric('junction_freeswitch_sessions_peak', 'gauge', 'Most call legs at once since FreeSWITCH started.', $fs['sessions_peak']);
                $this->metric('junction_freeswitch_sessions_total', 'counter', 'Call legs since FreeSWITCH started (rate() gives legs per second).', $fs['sessions_total']);
                $this->metric('junction_freeswitch_sessions_per_second', 'gauge', 'New call legs per second (FreeSWITCH\'s own figure).', $fs['sessions_per_second']);
                $this->metric('junction_freeswitch_max_sessions', 'gauge', 'Call leg limit.', $fs['max_sessions']);
                $this->metric('junction_freeswitch_max_sessions_per_second', 'gauge', 'New call legs per second limit.', $fs['max_sessions_per_second']);
                foreach ($fs['profiles'] ?? [] as $profile => $state) {
                    $this->metric('junction_freeswitch_profile_up', 'gauge', 'SIP profile running (1) or not (0).', $state === 'RUNNING' ? 1 : 0, ['profile' => $profile]);
                }
            }
        }

        // Active calls (customer calls in progress), live.
        $this->metric('junction_active_calls', 'gauge', 'Calls in progress.', DB::table('active_calls')->count());
        foreach (DB::table('active_calls')->join('accounts', 'accounts.id', '=', 'active_calls.account_id')
            ->groupBy('accounts.code')->selectRaw('accounts.code, count(*) AS n')->get() as $row) {
            $this->metric('junction_active_calls_by_customer', 'gauge', 'Calls in progress per customer.', $row->n, ['customer' => $row->code]);
        }
        $this->quality();
        foreach (['warning', 'critical'] as $severity) {
            $this->metric('junction_open_alerts', 'gauge', 'Alerts open or acknowledged.',
                DB::table('alerts')->whereIn('status', ['open', 'acknowledged'])->where('severity', $severity)->count(), ['severity' => $severity]);
        }

        if ($slow) {
            $this->metric('junction_queue_jobs', 'gauge', 'Background jobs waiting.', $slow['queue']['pending']);
            $this->metric('junction_cdr_waiting', 'gauge', 'Call records FreeSWITCH could not save yet.', $slow['cdr']['waiting']);
            if ($slow['backup']['last_ok_at']) {
                $this->metric('junction_backup_last_success_timestamp_seconds', 'gauge', 'When the last good backup finished.', CarbonImmutable::parse($slow['backup']['last_ok_at'])->getTimestamp());
            }
            if ($slow['certificate']['expires']) {
                $this->metric('junction_certificate_expiry_timestamp_seconds', 'gauge', 'When the SSL certificate expires.', CarbonImmutable::parse($slow['certificate']['expires'])->getTimestamp());
            }
            $this->metric('junction_database_bytes', 'gauge', 'Database size.', $slow['database']['bytes']);
            if (is_array($slow['ha'])) {
                $this->metric('junction_ha_backup_connected', 'gauge', 'Backup server connected (1) or not (0).', $slow['ha']['connected'] ? 1 : 0);
                if ($slow['ha']['lag'] !== null) {
                    $this->metric('junction_ha_backup_lag_seconds', 'gauge', 'How far behind the backup server is.', $slow['ha']['lag']);
                }
            }
        }

        return response(implode("\n", array_merge(...array_values($this->metrics)))."\n", 200, ['Content-Type' => 'text/plain; version=0.0.4; charset=utf-8', 'Cache-Control' => 'no-store']);
    }

    /**
     * Call quality over the calls that ended in the last 15 minutes, per carrier
     * trunk and per customer. Windowed on the end time because a call's record
     * (and its carrier attempts) only arrives when it ends: counting by start
     * time would miss the answered calls still in progress and understate ASR.
     * The counts are there so Grafana can add trunks and customers up
     * (ASR = sum(answered) / sum(attempts)); the ratios are for quick panels.
     */
    private function quality(): void
    {
        $since = now()->subMinutes(15);
        $floor = now()->subDay(); // keeps the scan to the recent months' partitions

        // Same failure classes as QualityRollup (the Quality page).
        $carriers = DB::table('cdr_attempts as a')
            ->join('cdrs as c', fn ($j) => $j->on('c.id', '=', 'a.cdr_id')->on('c.started_at', '=', 'a.cdr_started_at'))
            ->join('trunks as t', 't.id', '=', 'a.trunk_id')
            ->join('accounts as acc', 'acc.id', '=', 't.account_id')
            ->where('c.started_at', '>=', $floor)->where('a.cdr_started_at', '>=', $floor)->where('c.ended_at', '>=', $since)
            ->groupBy('acc.code', 't.name')
            ->selectRaw(<<<'SQL'
                acc.code AS carrier, t.name AS trunk, count(*) AS attempts, count(a.answered_at) AS answered,
                coalesce(sum(c.billsec) FILTER (WHERE a.answered_at IS NOT NULL), 0) AS billsec,
                avg(a.pdd_ms) AS pdd,
                count(*) FILTER (WHERE a.answered_at IS NULL AND a.sip_code IN (486, 600)) AS busy,
                count(*) FILTER (WHERE a.answered_at IS NULL AND (a.sip_code IN (480, 408) OR a.hangup_cause IN ('NO_ANSWER', 'NO_USER_RESPONSE'))) AS no_answer,
                count(*) FILTER (WHERE a.answered_at IS NULL AND (a.sip_code BETWEEN 500 AND 599
                                 OR a.hangup_cause IN ('NORMAL_TEMPORARY_FAILURE', 'NORMAL_CIRCUIT_CONGESTION', 'SWITCH_CONGESTION',
                                                       'PROGRESS_TIMEOUT', 'RECOVERY_ON_TIMER_EXPIRE', 'GATEWAY_DOWN', 'DESTINATION_OUT_OF_ORDER'))) AS congestion,
                count(*) FILTER (WHERE a.answered_at IS NULL AND (a.sip_code BETWEEN 400 AND 699)
                                 AND a.sip_code NOT IN (486, 600, 480, 408) AND a.sip_code NOT BETWEEN 500 AND 599) AS rejected
            SQL)->get();
        foreach ($carriers as $r) {
            $l = ['carrier' => (string) $r->carrier, 'trunk' => (string) $r->trunk];
            $this->metric('junction_carrier_attempts_15m', 'gauge', 'Carrier attempts in the calls that ended in the last 15 minutes.', (int) $r->attempts, $l);
            $this->metric('junction_carrier_answered_15m', 'gauge', 'Carrier attempts answered (last 15 minutes).', (int) $r->answered, $l);
            $this->metric('junction_carrier_billed_seconds_15m', 'gauge', 'Billed seconds of the answered attempts (last 15 minutes).', (int) $r->billsec, $l);
            foreach (['busy', 'no_answer', 'congestion', 'rejected'] as $reason) {
                $this->metric('junction_carrier_failures_15m', 'gauge', 'Failed carrier attempts by reason (last 15 minutes).', (int) $r->{$reason}, $l + ['reason' => $reason]);
            }
            $this->metric('junction_carrier_asr_percent', 'gauge', 'Carrier answer-seizure ratio, 0-100 (last 15 minutes).', round($r->answered / $r->attempts * 100, 2), $l);
            $this->metric('junction_carrier_acd_seconds', 'gauge', 'Carrier average call duration (last 15 minutes).', $r->answered ? round($r->billsec / $r->answered, 1) : null, $l);
            $this->metric('junction_carrier_pdd_seconds', 'gauge', 'Carrier average post-dial delay (last 15 minutes).', $r->pdd !== null ? round((float) $r->pdd / 1000, 3) : null, $l);
        }

        $customers = DB::table('cdrs as c')->join('accounts as acc', 'acc.id', '=', 'c.customer_account_id')
            ->where('c.started_at', '>=', $floor)->where('c.ended_at', '>=', $since)
            ->groupBy('acc.code')
            ->selectRaw('acc.code AS customer, count(*) AS calls, count(c.answered_at) AS answered, coalesce(sum(c.billsec), 0) AS billsec, avg(c.pdd_ms) AS pdd')
            ->get();
        foreach ($customers as $r) {
            $l = ['customer' => (string) $r->customer];
            $this->metric('junction_customer_calls_15m', 'gauge', 'Customer calls that ended in the last 15 minutes.', (int) $r->calls, $l);
            $this->metric('junction_customer_answered_15m', 'gauge', 'Customer calls answered (last 15 minutes).', (int) $r->answered, $l);
            $this->metric('junction_customer_billed_seconds_15m', 'gauge', 'Customer billed seconds (last 15 minutes).', (int) $r->billsec, $l);
            $this->metric('junction_customer_asr_percent', 'gauge', 'Customer answer-seizure ratio, 0-100 (last 15 minutes).', round($r->answered / $r->calls * 100, 2), $l);
            $this->metric('junction_customer_acd_seconds', 'gauge', 'Customer average call duration (last 15 minutes).', $r->answered ? round($r->billsec / $r->answered, 1) : null, $l);
            $this->metric('junction_customer_pdd_seconds', 'gauge', 'Customer average post-dial delay (last 15 minutes).', $r->pdd !== null ? round((float) $r->pdd / 1000, 3) : null, $l);
        }
    }

    /** @param array<string, string> $labels */
    private function metric(string $name, string $type, string $help, int|float|null $value, array $labels = []): void
    {
        if ($value === null) {
            return;
        }
        $this->metrics[$name] ??= ["# HELP {$name} {$help}", "# TYPE {$name} {$type}"];
        $l = $labels ? '{'.implode(',', array_map(fn ($k, $v) => $k.'="'.str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $v).'"', array_keys($labels), $labels)).'}' : '';
        $this->metrics[$name][] = $name.$l.' '.(is_float($value) ? rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.') : $value);
    }
}
