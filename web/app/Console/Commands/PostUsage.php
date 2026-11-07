<?php

namespace App\Console\Commands;

use App\Enums\LedgerEntryType;
use App\Models\Account;
use App\Services\Ledger;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Posts rated, unposted customer usage to the ledger: one usage entry per
 * account per 15 minutes of call start, dated by that batch's last call.
 * Every time zone's offset is a multiple of 15 minutes, so batches never cross
 * a billing period boundary and each period's ledger usage equals its call
 * records exactly. Until posted, usage already counts against the
 * customer's available funds in routing.
 */
class PostUsage extends Command
{
    protected $signature = 'softswitch:post-usage {--account= : Only this account id}';

    protected $description = 'Post rated call usage to customer ledgers';

    public function handle(Ledger $ledger): int
    {
        $accounts = DB::table('cdrs')->whereNull('posted_at')->whereNotNull('sell_cost')
            ->whereNotNull('customer_account_id')
            ->when($this->option('account'), fn ($q, $id) => $q->where('customer_account_id', (int) $id))
            ->whereNotIn('customer_account_id', DB::table('accounts')->select('id')->where('code', SelfTest::CUSTOMER))
            ->distinct()->pluck('customer_account_id');

        $entries = 0;
        foreach ($accounts as $accountId) {
            $entries += $this->postAccount((int) $accountId, $ledger);
        }

        $this->info("Posted {$entries} usage entr".($entries === 1 ? 'y' : 'ies').'.');

        return self::SUCCESS;
    }

    private function postAccount(int $accountId, Ledger $ledger): int
    {
        return DB::transaction(function () use ($accountId, $ledger) {
            // Lock this account's unposted CDRs so a concurrent run can't post them twice.
            $rows = DB::table('cdrs')
                ->where('customer_account_id', $accountId)->whereNull('posted_at')->whereNotNull('sell_cost')
                ->orderBy('started_at')->lockForUpdate()
                ->get(['id', 'sell_cost', 'started_at']);
            if ($rows->isEmpty()) {
                return 0;
            }

            $account = Account::query()->findOrFail($accountId);
            $posted = 0;
            $byQuarter = $rows->groupBy(function ($r) {
                $t = CarbonImmutable::parse($r->started_at)->utc();

                return $t->setTime($t->hour, intdiv($t->minute, 15) * 15)->toIso8601String();
            });
            foreach ($byQuarter as $bucket => $batch) {
                $total = $batch->reduce(fn (string $sum, $r) => bcadd($sum, (string) $r->sell_cost, 6), '0');
                DB::table('cdrs')->whereIn('id', $batch->pluck('id'))->update(['posted_at' => now()]);

                if (bccomp($total, '0', 6) > 0) {
                    $start = CarbonImmutable::parse($bucket);
                    $ledger->post(
                        $account,
                        LedgerEntryType::Usage,
                        Decimal::round(bcmul($total, '-1', 6)),
                        sprintf('Usage %s %s–%s UTC: %d call(s)', $start->format('Y-m-d'), $start->format('H:i'),
                            $start->addMinutes(15)->format('H:i'), $batch->count()),
                        effectiveAt: CarbonImmutable::parse($batch->max('started_at')),
                    );
                    $posted++;
                }
            }

            return $posted;
        });
    }
}
