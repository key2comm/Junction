<?php

namespace App\Jobs;

use App\Services\FirewallSync;
use App\Services\Freeswitch\GatewaySync;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pushes carrier configuration from the database to the switch: FreeSWITCH
 * gateways and the firewall allowlist. Dispatched (debounced) whenever
 * accounts' IPs, trunks or gateways change, and every 15 minutes to follow
 * vendor hostnames whose IPs change.
 */
class SyncSwitchConfig implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 10;

    public int $uniqueFor = 60;

    public function handle(GatewaySync $gateways, FirewallSync $firewall): void
    {
        $errors = [];
        foreach (['gateways' => fn () => $gateways->sync(), 'firewall' => fn () => $firewall->apply()] as $name => $sync) {
            try {
                Log::info("Switch config sync: {$name}", $sync());
            } catch (Throwable $e) {
                $errors[] = "{$name}: {$e->getMessage()}";
            }
        }
        if ($errors !== []) {
            throw new \RuntimeException(implode('; ', $errors));
        }
    }

    /** Queue a sync shortly after a burst of changes (one job per burst). */
    public static function soon(): void
    {
        static::dispatch()->delay(now()->addSeconds(2))->afterCommit();
    }
}
