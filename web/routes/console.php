<?php

use App\Jobs\RefreshFirewallStatus;
use App\Jobs\SyncSwitchConfig;
use App\Models\RateImport;
use App\Services\LiveCalls;
use App\Services\TrunkQuality;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

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
Schedule::job(new SyncSwitchConfig)->everyFifteenMinutes();

// Settings -> Firewall: fail2ban bans for the page, expired blacklist entries,
// and undoing firewall changes nobody confirmed.
Schedule::job(new RefreshFirewallStatus)->everyMinute();

// Monthly invoices: hourly, so each customer is invoiced two hours after the
// month ends in their own time zone (issueDue skips anyone not due yet).
Schedule::command('softswitch:invoices')->hourlyAt(5)->withoutOverlapping();

// Low-balance alerts (one email per dip below a customer's threshold).
Schedule::command('softswitch:check-balances')->everyFiveMinutes()->withoutOverlapping();

// Rate files uploaded but never matched to columns and imported: drop after a day.
Schedule::call(function () {
    foreach (RateImport::query()->where('status', 'mapping')->where('created_at', '<', now()->subDay())->get() as $import) {
        Storage::disk('local')->delete($import->path);
        $import->delete();
    }
})->name('softswitch:prune-rate-uploads')->daily();

// Vendor quality stats: rebuild the last few hours (catches late CDRs and leg timings).
Schedule::command('softswitch:quality-rollup')->everyFiveMinutes()->withoutOverlapping();
Schedule::call(fn () => DB::table('cdr_leg_timings')->where('created_at', '<', now()->subDays(2))->delete())
    ->name('softswitch:prune-leg-timings')->daily();

// Live calls: refresh the snapshot every 5 seconds, but only while someone is looking.
Schedule::call(function () {
    if (Cache::has(LiveCalls::WATCHING)) {
        app(LiveCalls::class)->refresh();
    }
})->name('softswitch:live-calls')->everyFiveSeconds()->withoutOverlapping();

// Fraud and operational alerts.
Schedule::command('softswitch:check-alerts')->everyMinute()->withoutOverlapping();

// Quality-aware routing reads the last hour's per-trunk figures, refreshed every minute.
Schedule::call(fn () => app(TrunkQuality::class)->refresh())->name('softswitch:trunk-quality')->everyMinute()->withoutOverlapping();
