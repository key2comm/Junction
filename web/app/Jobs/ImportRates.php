<?php

namespace App\Jobs;

use App\Models\RateImport;
use App\Services\RateImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ImportRates implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public RateImport $import) {}

    public function handle(RateImporter $importer): void
    {
        $importer->run($this->import);
    }
}
