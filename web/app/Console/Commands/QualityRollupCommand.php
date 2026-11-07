<?php

namespace App\Console\Commands;

use App\Services\QualityRollup;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class QualityRollupCommand extends Command
{
    protected $signature = 'softswitch:quality-rollup {--hours=3 : Rebuild this many recent hours} {--from= : Rebuild from this time (backfill)}';

    protected $description = 'Rebuild carrier quality statistics (quality_hourly) for recent hours';

    public function handle(QualityRollup $rollup): int
    {
        $to = CarbonImmutable::now('UTC');
        $from = $this->option('from') ? CarbonImmutable::parse($this->option('from'))->utc() : $to->subHours(max(1, (int) $this->option('hours')));
        $rows = $rollup->rebuild($from, $to);
        $this->info("Rebuilt {$rows} quality row(s) from {$from->toIso8601String()}.");

        return self::SUCCESS;
    }
}
