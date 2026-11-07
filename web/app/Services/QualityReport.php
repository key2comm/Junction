<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Vendor quality figures from quality_hourly, for the admin page and the API.
 * ASR = answered / attempts (per vendor attempt), ACD = billed seconds per
 * answered call, PDD = average post-dial delay of attempts that rang/answered.
 */
class QualityReport
{
    public const GROUPS = ['vendor', 'trunk', 'prefix'];

    /** @return Collection<int, array<string, mixed>> */
    public function rows(CarbonImmutable $from, CarbonImmutable $to, string $group = 'trunk', ?int $vendorId = null, int $minAttempts = 1): Collection
    {
        $keys = match ($group) {
            'vendor' => ['q.vendor_account_id'],
            'prefix' => ['q.vendor_account_id', 'q.trunk_id', 'q.prefix'],
            default => ['q.vendor_account_id', 'q.trunk_id'],
        };

        $rows = DB::table('quality_hourly as q')
            ->join('accounts as v', 'v.id', '=', 'q.vendor_account_id')
            ->leftJoin('trunks as t', 't.id', '=', 'q.trunk_id')
            ->where('q.hour', '>=', $from)->where('q.hour', '<', $to)
            ->when($vendorId, fn ($q, $id) => $q->where('q.vendor_account_id', $id))
            ->groupBy([...$keys, 'v.code', 'v.name', ...($group === 'vendor' ? [] : ['t.name'])])
            ->selectRaw(implode(', ', [
                'q.vendor_account_id AS vendor_id', 'v.code AS vendor_code', 'v.name AS vendor_name',
                $group === 'vendor' ? 'NULL AS trunk_id, NULL AS trunk_name' : 'q.trunk_id AS trunk_id, t.name AS trunk_name',
                $group === 'prefix' ? 'q.prefix AS prefix' : 'NULL AS prefix',
                'sum(q.attempts) AS attempts', 'sum(q.answered) AS answered', 'sum(q.billsec) AS billsec',
                'sum(q.pdd_ms_sum) AS pdd_ms_sum', 'sum(q.pdd_count) AS pdd_count',
                'sum(q.busy) AS busy', 'sum(q.no_answer) AS no_answer', 'sum(q.congestion) AS congestion', 'sum(q.rejected) AS rejected',
            ]))
            ->havingRaw('sum(q.attempts) >= ?', [$minAttempts])
            ->orderByRaw('sum(q.attempts) DESC')
            ->limit(500)
            ->get();

        return $rows->map(fn ($r) => $this->metrics($r));
    }

    /**
     * Hourly ASR per trunk, for the strip on the quality page.
     *
     * @return array<int, list<array{hour: string, attempts: int, asr: ?float}>>
     */
    public function hourlyByTrunk(CarbonImmutable $from, CarbonImmutable $to, array $trunkIds): array
    {
        if ($trunkIds === []) {
            return [];
        }
        $rows = DB::table('quality_hourly')->whereIn('trunk_id', $trunkIds)->where('hour', '>=', $from)->where('hour', '<', $to)
            ->groupBy('trunk_id', 'hour')->orderBy('hour')
            ->selectRaw('trunk_id, hour, sum(attempts) AS attempts, sum(answered) AS answered')->get();

        $series = [];
        foreach ($rows as $r) {
            $series[$r->trunk_id][] = [
                'hour' => CarbonImmutable::parse($r->hour)->utc()->format('Y-m-d\TH:i:s\Z'),
                'attempts' => (int) $r->attempts,
                'asr' => (int) $r->attempts ? round($r->answered / $r->attempts * 100, 1) : null,
            ];
        }

        return $series;
    }

    private function metrics(object $r): array
    {
        $attempts = (int) $r->attempts;
        $answered = (int) $r->answered;
        $pct = fn ($n) => $attempts ? round($n / $attempts * 100, 1) : null;

        return [
            'vendor_id' => (int) $r->vendor_id,
            'vendor_code' => $r->vendor_code,
            'vendor_name' => $r->vendor_name,
            'trunk_id' => $r->trunk_id !== null ? (int) $r->trunk_id : null,
            'trunk_name' => $r->trunk_name,
            'prefix' => $r->prefix !== null ? (string) $r->prefix : null,
            'attempts' => $attempts,
            'answered' => $answered,
            'minutes' => round((int) $r->billsec / 60, 1),
            'asr' => $pct($answered),
            'acd' => $answered ? (int) round((int) $r->billsec / $answered) : null,
            'pdd_ms' => (int) $r->pdd_count ? (int) round((int) $r->pdd_ms_sum / (int) $r->pdd_count) : null,
            'busy_pct' => $pct((int) $r->busy),
            'no_answer_pct' => $pct((int) $r->no_answer),
            'congestion_pct' => $pct((int) $r->congestion),
            'rejected_pct' => $pct((int) $r->rejected),
        ];
    }
}
