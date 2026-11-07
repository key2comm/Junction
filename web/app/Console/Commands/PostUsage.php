<?php

namespace App\Console\Commands;

use App\Enums\LedgerEntryType;
use App\Models\Account;
use App\Services\Ledger;
use App\Support\Decimal;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Posts rated, unposted customer usage to the ledger: one usage entry per
 * account per run, then marks those CDRs posted. Until then, unposted usage
 * already counts against the customer's available funds in routing.
 */
class PostUsage extends Command
{
    protected $signature = 'softswitch:post-usage';

    protected $description = 'Post rated call usage to customer ledgers';

    public function handle(Ledger $ledger): int
    {
        $accounts = DB::table('cdrs')->whereNull('posted_at')->whereNotNull('sell_cost')
            ->whereNotNull('customer_account_id')
            ->whereNotIn('customer_account_id', DB::table('accounts')->select('id')->where('code', SelfTest::CUSTOMER))
            ->distinct()->pluck('customer_account_id');

        $posted = 0;
        foreach ($accounts as $accountId) {
            DB::transaction(function () use ($accountId, $ledger, &$posted) {
                // Lock this account's unposted CDRs so a concurrent run can't post them twice.
                $rows = DB::table('cdrs')
                    ->where('customer_account_id', $accountId)->whereNull('posted_at')->whereNotNull('sell_cost')
                    ->lockForUpdate()->get(['id', 'sell_cost', 'started_at']);
                if ($rows->isEmpty()) {
                    return;
                }

                $total = $rows->reduce(fn (string $sum, $r) => bcadd($sum, (string) $r->sell_cost, 6), '0');
                DB::table('cdrs')->whereIn('id', $rows->pluck('id'))->update(['posted_at' => now()]);

                if (bccomp($total, '0', 6) > 0) {
                    $account = Account::query()->findOrFail($accountId);
                    $from = $rows->min('started_at');
                    $to = $rows->max('started_at');
                    $ledger->post(
                        $account,
                        LedgerEntryType::Usage,
                        Decimal::round(bcmul($total, '-1', 6)),
                        sprintf('Usage: %d call(s), %s to %s UTC', $rows->count(), substr($from, 0, 16), substr($to, 0, 16)),
                    );
                    $posted++;
                }
            });
        }

        $this->info("Posted usage for {$posted} account(s).");

        return self::SUCCESS;
    }
}
