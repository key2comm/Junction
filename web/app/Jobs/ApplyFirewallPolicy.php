<?php

namespace App\Jobs;

use App\Services\FirewallPolicy;
use App\Services\FirewallSync;
use App\Support\Settings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Pushes Settings -> Firewall (whitelist, blacklist, lockdown) to the firewall.
 * $confirm: apply with the helper's rollback timer (see FirewallPolicy).
 */
class ApplyFirewallPolicy implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public bool $confirm = false, public bool $rollbackFirst = false) {}

    public function handle(FirewallSync $firewall): void
    {
        try {
            $firewall->applyPolicy($this->confirm ? FirewallPolicy::CONFIRM_SECONDS : null, $this->rollbackFirst);
            if (! $this->confirm) {
                Cache::forget(FirewallPolicy::ERROR);
            }
        } catch (Throwable $e) {
            Cache::put(FirewallPolicy::ERROR, $e->getMessage(), now()->addDay());
            // A change that couldn't be applied isn't waiting for anyone: fall back now.
            if ($this->confirm && is_array(Settings::get(FirewallPolicy::PENDING))) {
                $pending = Settings::get(FirewallPolicy::PENDING);
                Settings::put([FirewallPolicy::LOCKDOWN => $pending['fallback'] ?? [], FirewallPolicy::PENDING => null]);
                Cache::put(FirewallPolicy::ERROR, "The firewall change \"{$pending['what']}\" was not applied: {$e->getMessage()}", now()->addDay());
            }
            throw $e;
        }
    }
}
