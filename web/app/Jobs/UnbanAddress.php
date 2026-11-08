<?php

namespace App\Jobs;

use App\Services\FirewallSync;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Lifts a fail2ban ban (every jail), then refreshes the ban list. */
class UnbanAddress implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public string $ip) {}

    public function handle(FirewallSync $firewall): void
    {
        $firewall->unban($this->ip);
        RefreshFirewallStatus::dispatchSync();
    }
}
