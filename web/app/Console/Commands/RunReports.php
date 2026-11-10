<?php

namespace App\Console\Commands;

use App\Services\ReportRunner;
use Illuminate\Console\Command;

/** Settings → Scheduled reports: make the reports whose period has ended, and delete expired ones. */
class RunReports extends Command
{
    protected $signature = 'softswitch:reports';

    protected $description = 'Make scheduled call record reports for periods that have ended; delete reports past their retention';

    public function handle(ReportRunner $runner): int
    {
        $made = $runner->runDue();
        $deleted = $runner->prune();
        $this->info("{$made} report(s) made, {$deleted} expired report(s) deleted.");

        return self::SUCCESS;
    }
}
