<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Settings → Scheduled reports: a call records CSV made for every day, week
 * or month that ends (see App\Services\ReportRunner).
 */
#[Hidden(['sftp_password', 'sftp_private_key'])]
#[Fillable(['name', 'enabled', 'frequency', 'timezone', 'filters', 'retention_days', 'email_to', 'sftp_enabled', 'sftp_host', 'sftp_port',
    'sftp_username', 'sftp_password', 'sftp_private_key', 'sftp_path', 'sftp_fingerprint', 'last_run_at', 'last_error'])]
class ReportSchedule extends Model
{
    public const FREQUENCIES = ['daily' => 'Daily (the day before)', 'weekly' => 'Weekly (Monday to Sunday)', 'monthly' => 'Monthly (the month before)'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'filters' => 'array',
            'retention_days' => 'integer',
            'sftp_enabled' => 'boolean',
            'sftp_port' => 'integer',
            'sftp_password' => 'encrypted',
            'sftp_private_key' => 'encrypted',
            'last_run_at' => 'datetime',
        ];
    }

    public function files(): HasMany
    {
        return $this->hasMany(ReportFile::class);
    }

    /** @return list<string> */
    public function emailAddresses(): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', (string) $this->email_to) ?: [])));
    }

    /**
     * The last whole period that has ended by $now, in the schedule's time zone
     * (UTC start and end).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function lastPeriod(?CarbonImmutable $now = null): array
    {
        $local = ($now ?? CarbonImmutable::now())->setTimezone($this->timezone ?: 'UTC');
        [$start, $end] = match ($this->frequency) {
            'weekly' => [$local->startOfWeek(CarbonImmutable::MONDAY)->subWeek(), $local->startOfWeek(CarbonImmutable::MONDAY)],
            'monthly' => [$local->startOfMonth()->subMonthNoOverflow(), $local->startOfMonth()],
            default => [$local->startOfDay()->subDay(), $local->startOfDay()],
        };

        return [$start->utc(), $end->utc()];
    }
}
