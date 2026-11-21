<?php

namespace App\Console\Commands;

use App\Services\BackupRunner;
use App\Services\HighAvailability;
use Illuminate\Console\Command;

/**
 * Settings → Backups. The scheduler runs `softswitch:backup --if-due` every
 * minute: a backup is made once the daily time has passed, or after Run now.
 * Without --if-due a backup is made straight away.
 */
class RunBackup extends Command
{
    protected $signature = 'softswitch:backup {--if-due : only when the daily time has passed or Run now was pressed}';

    protected $description = 'Back up the database (Settings → Backups)';

    public function handle(BackupRunner $runner): int
    {
        // A backup server's database is a copy of the main server's, which makes its own backups.
        if (HighAvailability::role() === 'standby') {
            $this->option('if-due') || $this->error('This is the backup (standby) server: backups are made on the main server.');

            return $this->option('if-due') ? self::SUCCESS : self::FAILURE;
        }
        $trigger = $this->option('if-due') ? $runner->due() : 'manual';
        if ($trigger === null) {
            return self::SUCCESS;
        }
        $backup = $runner->run($trigger);
        $this->line($backup->status === 'done' ? "Backup {$backup->filename} made." : "Backup failed: {$backup->error}");

        return $backup->status === 'done' ? self::SUCCESS : self::FAILURE;
    }
}
