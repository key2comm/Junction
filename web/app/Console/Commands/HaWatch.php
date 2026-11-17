<?php

namespace App\Console\Commands;

use App\Mail\HaMail;
use App\Services\HighAvailability;
use App\Services\MailSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * On the backup server, every minute (softswitch-ha tick): is the copy still
 * in sync with the main server? Emails the admin address when that changes.
 * (The backup can't record alerts: its database is read-only. The main server
 * raises its own "backup not in sync" alert.)
 */
class HaWatch extends Command
{
    protected $signature = 'softswitch:ha-watch';

    protected $description = 'Backup server: check replication from the main server and email when it breaks or recovers';

    public function handle(): int
    {
        if (! HighAvailability::isStandby()) {
            return self::SUCCESS;
        }

        $main = HighAvailability::main();
        $file = HighAvailability::stateDir().'/watch.json';
        $last = is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
        $wasHealthy = $last['healthy'] ?? true;
        // Wait for two bad checks in a row before emailing, so a blip doesn't.
        $bad = $main['healthy'] ? 0 : (($last['bad'] ?? 0) + 1);

        $state = ['healthy' => $main['healthy'] || $bad < 2, 'bad' => $bad, 'checked_at' => now()->toIso8601String(), 'main' => $main];
        @mkdir(dirname($file), 0750, true);
        file_put_contents($file, json_encode($state));

        if ($wasHealthy && ! $state['healthy']) {
            $why = $main['streaming']
                ? "The newest data from the main server is {$this->ago($main['behind'])} old."
                : "This backup can't reach the main server's database ({$main['main']}).";
            $this->email('Backup server: the main server is not reachable', [
                $why,
                'If the main server is down, make this backup the main server: sign in to it and click "Make this the main server" (or run: sudo softswitch-ha promote). Then point your DNS name at the backup.',
            ]);
        } elseif (! $wasHealthy && $state['healthy']) {
            $this->email('Backup server: back in sync with the main server', ['Replication from the main server is working again.']);
        }
        $this->line($main['healthy'] ? 'In sync' : 'Not in sync');

        return self::SUCCESS;
    }

    private function ago(?int $seconds): string
    {
        return $seconds === null ? 'unknown' : ($seconds >= 120 ? intdiv($seconds, 60).' minutes' : "{$seconds} seconds");
    }

    /** @param list<string> $lines */
    private function email(string $subject, array $lines): void
    {
        if (! MailSettings::fresh() || ! ($to = MailSettings::adminAddress())) {
            return;
        }
        try {
            // By IP: the shared DNS name points at the main server until a failover.
            $ip = config('softswitch.public_ip');
            Mail::to($to)->send(new HaMail($subject, $lines, $ip ? "https://{$ip}/backup-server" : url('/backup-server')));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
