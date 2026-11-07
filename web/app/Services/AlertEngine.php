<?php

namespace App\Services;

use App\Console\Commands\SelfTest;
use App\Enums\AccountStatus;
use App\Mail\AlertMail;
use App\Models\Account;
use App\Models\Alert;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Evaluates the alert rules (every minute) against recent call records.
 * Each finding is upserted by its dedupe key: new problems are opened (and
 * emailed once, and may suspend the customer); continuing ones are refreshed;
 * operational problems that cleared are resolved automatically. Fraud alerts
 * stay until an admin acknowledges or resolves them.
 */
class AlertEngine
{
    public const RULES = [
        'spend_spike' => ['label' => 'Spend spike', 'kind' => 'fraud', 'severity' => 'critical',
            'help' => "A customer's spend in the last hour is over {multiplier}× their average hourly spend of the previous 7 days, and at least {min_amount}."],
        'new_destinations' => ['label' => 'New destinations', 'kind' => 'fraud', 'severity' => 'critical',
            'help' => 'A customer with history calls {count} or more destination prefixes in an hour that they never called in the previous 30 days.'],
        'high_cost_burst' => ['label' => 'High-cost burst', 'kind' => 'fraud', 'severity' => 'critical',
            'help' => '{count} or more calls in 15 minutes to destinations selling at {rate}/min or more.'],
        'short_call_pattern' => ['label' => 'Short-call pattern', 'kind' => 'fraud', 'severity' => 'warning',
            'help' => '{count} or more answered calls of {max_seconds} seconds or less to the same number within an hour.'],
        'vendor_asr_low' => ['label' => 'Carrier ASR low', 'kind' => 'operational', 'severity' => 'warning',
            'help' => "A trunk's answer rate over the last hour is below {asr}% (with at least {min_attempts} attempts)."],
        'vendor_pdd_high' => ['label' => 'Carrier PDD high', 'kind' => 'operational', 'severity' => 'warning',
            'help' => "A trunk's average post-dial delay over the last hour is above {pdd_ms} ms (with at least {min_attempts} measured attempts)."],
        'gateway_failing' => ['label' => 'Gateway failing', 'kind' => 'operational', 'severity' => 'critical',
            'help' => "A gateway's last {attempts} attempts (within the last hour) all failed."],
    ];

    /** @return array{opened: int, updated: int, resolved: int} */
    public function run(): array
    {
        $rules = DB::table('alert_rules')->where('enabled', true)->get()->keyBy('type');
        $counts = ['opened' => 0, 'updated' => 0, 'resolved' => 0];

        foreach (self::RULES as $type => $meta) {
            $rule = $rules[$type] ?? null;
            if (! $rule) {
                continue;
            }
            $params = json_decode($rule->params, true) ?: [];
            try {
                $findings = $this->{'check'.str_replace('_', '', ucwords($type, '_'))}($params);
            } catch (Throwable $e) {
                Log::error("Alert rule {$type} failed", ['error' => $e->getMessage()]);

                continue;
            }

            $seen = [];
            foreach ($findings as $f) {
                $seen[] = $f['key'];
                $live = Alert::query()->where('key', $f['key'])->whereIn('status', ['open', 'acknowledged'])->first();
                if ($live) {
                    $live->update(['details' => $f['details'], 'title' => $f['title'], 'last_seen_at' => now()]);
                    $counts['updated']++;

                    continue;
                }
                $alert = Alert::create([...$f, 'type' => $type, 'severity' => $meta['severity'], 'status' => 'open',
                    'opened_at' => now(), 'last_seen_at' => now()]);
                $counts['opened']++;

                if ($meta['kind'] === 'fraud' && $rule->auto_suspend && $alert->account_id) {
                    $this->suspend($alert);
                }
                $this->email($alert);
            }

            // Operational problems that are no longer happening resolve themselves.
            if ($meta['kind'] === 'operational') {
                $counts['resolved'] += Alert::query()->where('type', $type)->where('status', 'open')
                    ->whereNotIn('key', $seen)->update(['status' => 'resolved', 'resolved_at' => now()]);
            }
        }

        return $counts;
    }

    private function suspend(Alert $alert): void
    {
        $account = Account::query()->find($alert->account_id);
        if ($account && $account->status === AccountStatus::Active) {
            $account->update(['status' => AccountStatus::Suspended]);
            $alert->update(['action_taken' => 'Customer suspended automatically']);
        }
    }

    private function email(Alert $alert): void
    {
        if (! MailSettings::fresh() || ! ($to = MailSettings::adminAddress())) {
            return;
        }
        try {
            Mail::to($to)->send(new AlertMail($alert->fresh(['account', 'trunk', 'gateway'])));
            $alert->update(['emailed_at' => now()]);
        } catch (Throwable $e) {
            Log::warning('Alert email failed', ['alert' => $alert->id, 'error' => $e->getMessage()]);
        }
    }

    /** Accounts excluded from rules (the built-in self-test). */
    private function excluded(): array
    {
        return DB::table('accounts')->whereIn('code', [SelfTest::CUSTOMER, SelfTest::VENDOR])->pluck('id')->all() ?: [0];
    }

    private function code(int $accountId): string
    {
        return (string) DB::table('accounts')->where('id', $accountId)->value('code');
    }

    // --- fraud rules (customers) ---

    private function checkSpendSpike(array $p): array
    {
        $min = (float) ($p['min_amount'] ?? 50);
        $multiplier = (float) ($p['multiplier'] ?? 5);
        $recent = DB::table('cdrs')->where('started_at', '>=', now()->subHour())->whereNotNull('sell_cost')
            ->whereNotIn('customer_account_id', $this->excluded())->whereNotNull('customer_account_id')
            ->groupBy('customer_account_id')->havingRaw('sum(sell_cost) >= ?', [$min])
            ->selectRaw('customer_account_id AS id, sum(sell_cost) AS spend')->get();

        $out = [];
        foreach ($recent as $r) {
            $baseline = (float) DB::table('cdrs')->where('customer_account_id', $r->id)
                ->where('started_at', '>=', now()->subDays(7)->subHour())->where('started_at', '<', now()->subHour())
                ->sum('sell_cost') / (7 * 24);
            if ((float) $r->spend > $multiplier * $baseline) {
                $out[] = ['key' => "spend_spike:{$r->id}", 'account_id' => $r->id,
                    'title' => sprintf('%s spent %.2f in the last hour (usual: %.2f/hour)', $this->code($r->id), $r->spend, $baseline),
                    'details' => ['last_hour' => round((float) $r->spend, 4), 'hourly_average_7d' => round($baseline, 4)]];
            }
        }

        return $out;
    }

    private function checkNewDestinations(array $p): array
    {
        $rows = DB::select(<<<'SQL'
            WITH recent AS (
                SELECT customer_account_id AS a, coalesce(sell_prefix, left(destination, 4)) AS p
                FROM cdrs
                WHERE started_at >= now() - interval '1 hour' AND customer_account_id IS NOT NULL AND destination IS NOT NULL
                  AND customer_account_id <> ALL (?::bigint[])
                GROUP BY 1, 2
            )
            SELECT r.a AS id, count(*) AS n, (array_agg(r.p ORDER BY r.p))[1:20] AS prefixes
            FROM recent r
            WHERE NOT EXISTS (
                SELECT 1 FROM cdrs c
                WHERE c.customer_account_id = r.a AND coalesce(c.sell_prefix, left(c.destination, 4)) = r.p
                  AND c.started_at >= now() - interval '30 days' AND c.started_at < now() - interval '1 hour'
            )
            AND EXISTS (SELECT 1 FROM cdrs h WHERE h.customer_account_id = r.a AND h.started_at < now() - interval '1 hour'
                        AND h.started_at >= now() - interval '30 days')
            GROUP BY r.a
            HAVING count(*) >= ?
        SQL, ['{'.implode(',', $this->excluded()).'}', (int) ($p['count'] ?? 10)]);

        return array_map(fn ($r) => ['key' => "new_destinations:{$r->id}", 'account_id' => $r->id,
            'title' => "{$this->code($r->id)} called {$r->n} never-before-called destinations in the last hour",
            'details' => ['count' => (int) $r->n, 'prefixes' => array_values(array_filter(explode(',', trim((string) $r->prefixes, '{}'))))]], $rows);
    }

    private function checkHighCostBurst(array $p): array
    {
        $rows = DB::table('cdrs')->where('started_at', '>=', now()->subMinutes(15))->where('sell_rate', '>=', (float) ($p['rate'] ?? 1))
            ->whereNotNull('customer_account_id')->whereNotIn('customer_account_id', $this->excluded())
            ->groupBy('customer_account_id')->havingRaw('count(*) >= ?', [(int) ($p['count'] ?? 20)])
            ->selectRaw('customer_account_id AS id, count(*) AS n, max(sell_rate) AS top_rate')->get();

        return $rows->map(fn ($r) => ['key' => "high_cost_burst:{$r->id}", 'account_id' => $r->id,
            'title' => "{$this->code($r->id)} made {$r->n} calls to high-cost destinations in 15 minutes",
            'details' => ['calls' => (int) $r->n, 'highest_rate' => $r->top_rate]])->all();
    }

    private function checkShortCallPattern(array $p): array
    {
        $rows = DB::table('cdrs')->where('started_at', '>=', now()->subHour())->whereNotNull('answered_at')
            ->where('billsec', '<=', (int) ($p['max_seconds'] ?? 10))->whereNotNull('customer_account_id')
            ->whereNotIn('customer_account_id', $this->excluded())
            ->groupBy('customer_account_id', 'destination')->havingRaw('count(*) >= ?', [(int) ($p['count'] ?? 30)])
            ->selectRaw('customer_account_id AS id, destination, count(*) AS n')->get();

        return $rows->map(fn ($r) => ['key' => "short_call_pattern:{$r->id}:{$r->destination}", 'account_id' => $r->id,
            'title' => "{$this->code($r->id)}: {$r->n} very short answered calls to {$r->destination} in an hour",
            'details' => ['destination' => $r->destination, 'calls' => (int) $r->n]])->all();
    }

    // --- operational rules (vendors) ---

    private function trunkStats(): Collection
    {
        return DB::table('cdr_attempts as a')->join('trunks as t', 't.id', '=', 'a.trunk_id')
            ->where('a.started_at', '>=', now()->subHour())->whereNotIn('t.account_id', $this->excluded())
            ->groupBy('a.trunk_id', 't.account_id', 't.name')
            ->selectRaw('a.trunk_id, t.account_id, t.name, count(*) AS attempts, count(a.answered_at) AS answered,
                         avg(a.pdd_ms) AS pdd, count(a.pdd_ms) AS pdd_n')->get();
    }

    private function checkVendorAsrLow(array $p): array
    {
        $asr = (float) ($p['asr'] ?? 15);
        $min = (int) ($p['min_attempts'] ?? 50);

        return $this->trunkStats()->filter(fn ($r) => $r->attempts >= $min && $r->answered / $r->attempts * 100 < $asr)
            ->map(fn ($r) => ['key' => "vendor_asr_low:{$r->trunk_id}", 'account_id' => $r->account_id, 'trunk_id' => $r->trunk_id,
                'title' => sprintf('%s / %s: ASR %.1f%% over the last hour', $this->code($r->account_id), $r->name, $r->answered / $r->attempts * 100),
                'details' => ['attempts' => (int) $r->attempts, 'answered' => (int) $r->answered]])->values()->all();
    }

    private function checkVendorPddHigh(array $p): array
    {
        $limit = (int) ($p['pdd_ms'] ?? 8000);
        $min = (int) ($p['min_attempts'] ?? 30);

        return $this->trunkStats()->filter(fn ($r) => $r->pdd_n >= $min && $r->pdd > $limit)
            ->map(fn ($r) => ['key' => "vendor_pdd_high:{$r->trunk_id}", 'account_id' => $r->account_id, 'trunk_id' => $r->trunk_id,
                'title' => sprintf('%s / %s: average PDD %.1fs over the last hour', $this->code($r->account_id), $r->name, $r->pdd / 1000),
                'details' => ['pdd_ms' => (int) round($r->pdd), 'measured' => (int) $r->pdd_n]])->values()->all();
    }

    private function checkGatewayFailing(array $p): array
    {
        $n = (int) ($p['attempts'] ?? 20);
        $rows = DB::select(<<<'SQL'
            SELECT x.gateway_id, g.name, g.trunk_id, t.account_id, count(*) AS n
            FROM (
                SELECT gateway_id, answered_at,
                       row_number() OVER (PARTITION BY gateway_id ORDER BY started_at DESC) AS rn
                FROM cdr_attempts
                WHERE started_at >= now() - interval '1 hour' AND gateway_id IS NOT NULL
            ) x
            JOIN gateways g ON g.id = x.gateway_id
            JOIN trunks t ON t.id = g.trunk_id
            WHERE x.rn <= ? AND t.account_id <> ALL (?::bigint[])
            GROUP BY x.gateway_id, g.name, g.trunk_id, t.account_id
            HAVING count(*) >= ? AND count(x.answered_at) = 0
        SQL, [$n, '{'.implode(',', $this->excluded()).'}', $n]);

        return array_map(fn ($r) => ['key' => "gateway_failing:{$r->gateway_id}", 'account_id' => $r->account_id, 'trunk_id' => $r->trunk_id,
            'gateway_id' => $r->gateway_id, 'title' => "Gateway {$r->name}: last {$r->n} attempts all failed",
            'details' => ['attempts' => (int) $r->n]], $rows);
    }
}
