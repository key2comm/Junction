<?php

namespace App\Jobs;

use App\Services\FirewallSync;
use App\Services\Freeswitch\GatewaySync;
use App\Services\Freeswitch\PbxSync;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pushes configuration from the database to the switch: FreeSWITCH gateways,
 * the firewall allowlist and the PBX (SIP users, IVR greetings). Dispatched
 * (debounced) whenever accounts' IPs, trunks, gateways, extensions or IVR
 * greetings change, and every 15 minutes to follow carrier hostnames whose IPs
 * change.
 */
class SyncSwitchConfig implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 10;

    public int $uniqueFor = 60;

    public function handle(GatewaySync $gateways, FirewallSync $firewall, PbxSync $pbx): void
    {
        $errors = [];
        foreach (['gateways' => fn () => $gateways->sync(), 'firewall' => fn () => $firewall->apply(), 'firewall policy' => fn () => $firewall->applyPolicy(), 'pbx' => fn () => $pbx->sync()] as $name => $sync) {
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
