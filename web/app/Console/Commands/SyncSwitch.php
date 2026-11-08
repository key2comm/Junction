<?php

namespace App\Console\Commands;

use App\Services\FirewallSync;
use App\Services\Freeswitch\GatewaySync;
use App\Services\Freeswitch\PbxSync;
use Illuminate\Console\Command;
use Throwable;

/**
 * Push config to the switch now (gateways, firewall allowlist, PBX users).
 * The installer runs this after every deploy; the queue does it on changes.
 */
class SyncSwitch extends Command
{
    protected $signature = 'softswitch:sync {--gateways : Only FreeSWITCH gateways} {--firewall : Only the firewall allowlist} {--pbx : Only PBX users and IVR greetings}';

    protected $description = 'Sync FreeSWITCH gateways, the firewall carrier allowlist and PBX users from the database';

    public function handle(GatewaySync $gateways, FirewallSync $firewall, PbxSync $pbx): int
    {
        $only = array_filter(['gateways' => $this->option('gateways'), 'firewall' => $this->option('firewall'), 'pbx' => $this->option('pbx')]);
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
                $policy = $firewall->applyPolicy();
                $this->info('Firewall policy: '.($policy['policy'] ?? $policy['skipped'] ?? 'applied').'.');
            } catch (Throwable $e) {
                $this->error('Firewall: '.$e->getMessage());
                $failed = true;
            }
        }
        if (! $only || isset($only['pbx'])) {
            try {
                $r = $pbx->sync();
                $this->info(sprintf('PBX users: %d written, %d removed, %d unchanged%s; %d IVR greeting(s).', count($r['written']), count($r['removed']), $r['unchanged'],
                    $r['written'] || $r['removed'] ? ($r['reloaded'] ? ', FreeSWITCH reloaded' : ', FreeSWITCH NOT reloaded') : '', $r['greetings']));
            } catch (Throwable $e) {
                $this->error('PBX: '.$e->getMessage());
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
