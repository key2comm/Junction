<?php

namespace App\Jobs;

use App\Services\FirewallPolicy;
use App\Services\FirewallSync;
use App\Support\Settings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * The admin kept a pending firewall change: tell the helper to save it. If the
 * helper had already rolled it back (too late), fall back and re-sync.
 */
class ConfirmFirewallPolicy implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** @param  array{ssh: bool, web: bool, pbx: bool}  $fallback */
    public function __construct(public array $fallback) {}

    public function handle(FirewallSync $firewall): void
    {
        if ($firewall->confirm()) {
            return;
        }
        Settings::put([FirewallPolicy::LOCKDOWN => $this->fallback]);
        Cache::put(FirewallPolicy::ERROR, 'The confirmation arrived after the firewall had already undone the change. Please make it again.', now()->addDay());
        $firewall->applyPolicy();
    }
}
