<?php

namespace App\Jobs;

use App\Models\RateImport;
use App\Services\RateImporter;
use App\Services\TariffBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** (Re)builds a tariff from its Carrier Rates and markup; recorded like a rate import (mode "build"). */
class BuildTariff implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public RateImport $import) {}

    public function handle(TariffBuilder $builder, RateImporter $importer): void
    {
        $import = $this->import;
        $import->update(['status' => 'running', 'started_at' => now(), 'errors' => null, 'stats' => null]);
        try {
            $tariff = $import->rateDeck;
            $rows = $builder->rows($tariff, CarbonImmutable::parse($import->effective_from));
            if ($rows === []) {
                $import->update(['status' => 'failed', 'errors' => ['The chosen Carrier Rates have no rates in effect at that time.'], 'finished_at' => now()]);

                return;
            }
            $stats = $importer->apply($import, $rows);
            $tariff->forceFill(['built_at' => now()])->save();
            $import->update(['status' => 'done', 'stats' => $stats, 'finished_at' => now()]);
        } catch (Throwable $e) {
            $import->update(['status' => 'failed', 'errors' => [$e->getMessage()], 'finished_at' => now()]);
        }
    }
}
