<?php

namespace App\Jobs;

use App\Models\Cdr;
use App\Services\PcapExtractor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Cut a finished call's SIP messages out of the raw capture (Settings → Call captures). */
class ExtractCallPcap implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $backoff = 60;

    public function __construct(public int $cdrId) {}

    public function handle(PcapExtractor $extractor): void
    {
        if ($cdr = Cdr::query()->find($this->cdrId)) {
            $extractor->extract($cdr);
        }
    }
}
