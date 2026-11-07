<?php

namespace App\Console\Commands;

use App\Services\AlertEngine;
use Illuminate\Console\Command;

class CheckAlerts extends Command
{
    protected $signature = 'softswitch:check-alerts';

    protected $description = 'Evaluate fraud and operational alert rules';

    public function handle(AlertEngine $engine): int
    {
        $r = $engine->run();
        $this->info("{$r['opened']} opened, {$r['updated']} still active, {$r['resolved']} resolved.");

        return self::SUCCESS;
    }
}
