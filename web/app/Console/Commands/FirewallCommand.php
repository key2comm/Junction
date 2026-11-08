<?php

namespace App\Console\Commands;

use App\Models\FirewallEntry;
use App\Services\FirewallPolicy;
use App\Services\FirewallSync;
use App\Support\Settings;
use Illuminate\Console\Command;

/**
 * Settings -> Firewall from the command line, e.g. when a lockdown keeps you
 * out of the admin pages:
 *
 *   sudo -u softswitch php artisan softswitch:firewall status
 *   sudo -u softswitch php artisan softswitch:firewall unlock            # every service
 *   sudo -u softswitch php artisan softswitch:firewall unlock --service=web
 */
class FirewallCommand extends Command
{
    protected $signature = 'softswitch:firewall {action : status | unlock} {--service=* : ssh, web or pbx (unlock; default all)}';

    protected $description = 'Show the firewall lists and lockdown, or turn a lockdown off';

    public function handle(FirewallSync $firewall): int
    {
        return match ($this->argument('action')) {
            'status' => $this->status(),
            'unlock' => $this->unlock($firewall),
            default => $this->invalid(),
        };
    }

    private function status(): int
    {
        foreach (FirewallPolicy::lockdown() as $service => $on) {
            $this->line(sprintf('%-28s %s', FirewallPolicy::SERVICES[$service], $on ? 'locked to the whitelist' : 'open'));
        }
        if ($pending = FirewallPolicy::pending()) {
            $this->warn("Waiting for confirmation until {$pending['until']}: {$pending['what']}");
        }
        foreach (['allow' => 'Whitelist', 'block' => 'Blacklist'] as $list => $title) {
            $this->newLine();
            $this->info($title);
            $rows = FirewallEntry::query()->where('list', $list)->orderBy('value')->get()
                ->map(fn ($e) => [$e->value, implode(' ', $e->isHostname() ? ($e->resolved ?? []) : []), $e->reason, $e->expires_at?->toDateTimeString() ?? '']);
            $this->table(['Address', 'Resolves to', 'Reason', 'Expires'], $rows);
        }

        return self::SUCCESS;
    }

    private function unlock(FirewallSync $firewall): int
    {
        $services = $this->option('service') ?: ['ssh', 'web', 'pbx'];
        $lockdown = FirewallPolicy::lockdown();
        foreach ($services as $service) {
            if (! array_key_exists($service, $lockdown)) {
                $this->error("Unknown service {$service} (ssh, web or pbx).");

                return self::FAILURE;
            }
            $lockdown[$service] = false;
        }
        Settings::put([FirewallPolicy::LOCKDOWN => $lockdown, FirewallPolicy::PENDING => null]);
        $firewall->applyPolicy(rollbackFirst: true);
        $this->info('Unlocked: '.implode(', ', array_map(fn ($s) => FirewallPolicy::SERVICES[$s], $services)).'.');

        return self::SUCCESS;
    }

    private function invalid(): int
    {
        $this->error('Action must be status or unlock.');

        return self::FAILURE;
    }
}
