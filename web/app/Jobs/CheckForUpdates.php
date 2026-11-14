<?php

namespace App\Jobs;

use App\Services\UpdateCheck;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Dashboard → "Check now": look for a newer release from the queue worker. */
class CheckForUpdates implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(UpdateCheck $check): void
    {
        $check->check();
    }
}
