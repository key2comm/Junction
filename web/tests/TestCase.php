<?php

namespace Tests;

use App\Jobs\ApplyFirewallPolicy;
use App\Jobs\ConfirmFirewallPolicy;
use App\Jobs\RefreshFirewallStatus;
use App\Jobs\SyncSwitchConfig;
use App\Jobs\UnbanAddress;
use App\Support\Dns;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Queue;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Never touch the real switch (FreeSWITCH, firewall) from tests.
        Queue::fake([SyncSwitchConfig::class, ApplyFirewallPolicy::class, ConfirmFirewallPolicy::class, RefreshFirewallStatus::class, UnbanAddress::class]);
        Dns::fake([]);
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
