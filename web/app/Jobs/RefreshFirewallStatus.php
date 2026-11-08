<?php

namespace App\Jobs;

use App\Models\FirewallEntry;
use App\Services\FirewallPolicy;
use App\Services\FirewallSync;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Every minute: undo unconfirmed changes past their deadline, drop expired
 * blacklist entries, and read fail2ban's bans and the SSH clients for the
 * Settings -> Firewall page.
 */
class RefreshFirewallStatus implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 60;

    public function handle(FirewallSync $firewall): void
    {
        FirewallPolicy::finalizeExpired();

        $expired = FirewallEntry::query()->where('list', 'block')->whereNotNull('expires_at')->where('expires_at', '<=', now())->delete();
        if ($expired > 0) {
            ApplyFirewallPolicy::dispatch();
        }

        Cache::put(FirewallPolicy::STATUS, [...$firewall->status(), 'checked_at' => now()->toIso8601String()], now()->addHour());
    }
}
