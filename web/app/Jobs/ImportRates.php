<?php

namespace App\Jobs;

use App\Models\RateImport;
use App\Services\RateImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

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

    /** Called when the worker died mid-job (e.g. out of memory), so the import doesn't stay "running". */
    public function failed(?Throwable $e): void
    {
        $this->import->refresh();
        if (in_array($this->import->status, ['queued', 'running'], true)) {
            $this->import->update(['status' => 'failed', 'finished_at' => now(),
                'errors' => ['The import stopped unexpectedly'.($e ? ': '.$e->getMessage() : '.').' Please try again.']]);
        }
    }
}
