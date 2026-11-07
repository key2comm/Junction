<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

// Softswitch billing and housekeeping.
Schedule::command('softswitch:post-usage')->hourly()->withoutOverlapping();

// Release funds reservations for calls whose CDR never arrived (e.g. a FreeSWITCH
// crash). A call can't outlive its maximum duration, so older ones are stale.
Schedule::call(function () {
    DB::delete(
        "DELETE FROM active_calls WHERE created_at < now() - make_interval(secs => ?)",
        [config('softswitch.max_call_duration') + 600],
    );
})->name('softswitch:expire-reservations')->everyFiveMinutes();

// Follow vendor hostnames whose IPs change, and repair any drift.
Schedule::job(new \App\Jobs\SyncSwitchConfig)->everyFifteenMinutes();

// Monthly invoices: hourly, so each customer is invoiced two hours after the
// month ends in their own time zone (issueDue skips anyone not due yet).
Schedule::command('softswitch:invoices')->hourlyAt(5)->withoutOverlapping();
