<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Services\InvoiceGenerator;
use App\Support\Billing;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Issue customer invoices.
 *
 *   softswitch:invoices                       last month, for every customer that is due
 *   softswitch:invoices --account=12 --month=2026-10
 */
class IssueInvoices extends Command
{
    protected $signature = 'softswitch:invoices {--account= : Account id} {--month= : Billing month (YYYY-MM) for --account}';

    protected $description = 'Issue monthly customer invoices';

    public function handle(InvoiceGenerator $generator): int
    {
        if (! Billing::enabled()) {
            $this->info('Billing is turned off (Settings → Billing): no invoices issued.');

            return $this->option('account') ? self::FAILURE : self::SUCCESS;
        }
        if ($id = $this->option('account')) {
            $account = Account::query()->findOrFail((int) $id);
            $month = $this->option('month') ? CarbonImmutable::parse($this->option('month').'-01 12:00', $account->timezone) : now()->subMonthNoOverflow();
            [$start, $end] = InvoiceGenerator::monthPeriod($account, $month);
            try {
                $invoice = $generator->generate($account, $start, $end);
            } catch (RuntimeException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
            $this->info("Issued {$invoice->number} for {$account->code}: due {$invoice->amount_due} {$invoice->currency}.");

            return self::SUCCESS;
        }

        $results = $generator->issueDue();
        foreach ($results as $r) {
            $this->line("{$r['account']}: {$r['result']}");
        }
        $this->info(count($results).' account(s) processed.');

        return collect($results)->contains(fn ($r) => str_starts_with($r['result'], 'failed')) ? self::FAILURE : self::SUCCESS;
    }
}
