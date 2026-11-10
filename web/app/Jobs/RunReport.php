<?php

namespace App\Jobs;

use App\Models\ReportSchedule;
use App\Services\ReportRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Settings → Scheduled reports → Run now: (re)make the report for the last
 * ended period. On the queue: big reports take a while, and only the worker
 * may open network connections for email and SFTP (SELinux keeps PHP-FPM off).
 */
class RunReport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public int $scheduleId) {}

    public function handle(ReportRunner $runner): void
    {
        if ($schedule = ReportSchedule::query()->find($this->scheduleId)) {
            $runner->run($schedule);
        }
    }
}
