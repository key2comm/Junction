<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Call counts for the admin dashboard and the customer portal's overview:
 * today, this month, per day and per hour, in the viewer's time zone.
 * With a customer id, only that customer's calls count.
 */
final class CallStats
{
    public function __construct(private string $tz = 'UTC', private ?int $customerId = null) {}

    /** Everything the dashboards show, keyed by prop name. */
    public function all(): array
    {
        $now = CarbonImmutable::now('UTC');
        $local = $now->setTimezone($this->tz);

        return [
            'activeCalls' => DB::table('active_calls')->when($this->customerId, fn ($q) => $q->where('account_id', $this->customerId))->count(),
            'today' => $this->counts($local->startOfDay()),
            'month' => $this->counts($local->startOfMonth()),
            'week' => $this->buckets($local->startOfDay()->subDays(6), 7, 'day'),
            'hours' => $this->buckets($local->startOfHour()->subHours(23), 24, 'hour'),
        ];
    }

    private function cdrs(CarbonImmutable $from): Builder
    {
        return DB::table('cdrs')->where('started_at', '>=', $from->utc())
            ->when($this->customerId, fn ($q) => $q->where('customer_account_id', $this->customerId));
    }

    /** @return array{calls: int, answered: int, minutes: float} */
    private function counts(CarbonImmutable $from): array
    {
        $row = $this->cdrs($from)->selectRaw('count(*) AS calls, count(answered_at) AS answered, coalesce(sum(billsec), 0) AS billsec')->first();

        return ['calls' => (int) $row->calls, 'answered' => (int) $row->answered, 'minutes' => round((int) $row->billsec / 60, 1)];
    }

    /**
     * Calls per local day ("YYYY-MM-DD") or hour ("YYYY-MM-DD HH"), oldest
     * first, including periods with none.
     */
    private function buckets(CarbonImmutable $from, int $count, string $unit): array
    {
        [$sql, $php] = $unit === 'day' ? ['YYYY-MM-DD', 'Y-m-d'] : ['YYYY-MM-DD HH24', 'Y-m-d H'];
        $rows = $this->cdrs($from)
            ->selectRaw("to_char(started_at AT TIME ZONE ?, '{$sql}') AS bucket, count(*) AS calls, count(answered_at) AS answered", [$this->tz])
            ->groupBy('bucket')->get()->keyBy('bucket');

        return collect(range(0, $count - 1))->map(function (int $i) use ($from, $unit, $php, $rows) {
            $key = ($unit === 'day' ? $from->addDays($i) : $from->addHours($i))->format($php);
            $row = $rows->get($key);

            return [$unit => $key, 'calls' => (int) ($row->calls ?? 0), 'answered' => (int) ($row->answered ?? 0)];
        })->all();
    }
}
