<?php

namespace App\Jobs;

use App\Models\RateImport;
use App\Services\RateImporter;
use App\Services\RateImportRejected;
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
            $at = CarbonImmutable::parse($import->effective_from);
            $stats = $importer->apply($import, function () use ($builder, $tariff, $at) {
                return $builder->load($tariff, $at) ?: throw new RateImportRejected(['The chosen Carrier Rates have no rates in effect at that time.'], 0);
            });
            $tariff->forceFill(['built_at' => now()])->save();
            $import->update(['status' => 'done', 'stats' => $stats, 'finished_at' => now()]);
        } catch (RateImportRejected $e) {
            $import->update(['status' => 'failed', 'errors' => $e->errors, 'finished_at' => now()]);
        } catch (Throwable $e) {
            $import->update(['status' => 'failed', 'errors' => [$e->getMessage()], 'finished_at' => now()]);
        }
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
