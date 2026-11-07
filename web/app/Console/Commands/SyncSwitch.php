<?php

namespace App\Console\Commands;

use App\Services\FirewallSync;
use App\Services\Freeswitch\GatewaySync;
use Illuminate\Console\Command;
use Throwable;

/**
 * Push carrier config to the switch now (gateways + firewall allowlist).
 * The installer runs this after every deploy; the queue does it on changes.
 */
class SyncSwitch extends Command
{
    protected $signature = 'softswitch:sync {--gateways : Only FreeSWITCH gateways} {--firewall : Only the firewall allowlist}';

    protected $description = 'Sync FreeSWITCH gateways and the firewall carrier allowlist from the database';

    public function handle(GatewaySync $gateways, FirewallSync $firewall): int
    {
        $only = array_filter(['gateways' => $this->option('gateways'), 'firewall' => $this->option('firewall')]);
        $failed = false;

        if (! $only || isset($only['gateways'])) {
            try {
                $r = $gateways->sync();
                $this->info(sprintf('Gateways: %d written, %d removed, %d unchanged%s.', count($r['written']), count($r['removed']), $r['unchanged'],
                    $r['written'] || $r['removed'] ? ($r['reloaded'] ? ', FreeSWITCH reloaded' : ', FreeSWITCH NOT reloaded') : ''));
            } catch (Throwable $e) {
                $this->error('Gateways: '.$e->getMessage());
                $failed = true;
            }
        }
        if (! $only || isset($only['firewall'])) {
            try {
                $this->info('Firewall: '.$firewall->apply()['count'].' allowed carrier address(es).');
            } catch (Throwable $e) {
                $this->error('Firewall: '.$e->getMessage());
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
