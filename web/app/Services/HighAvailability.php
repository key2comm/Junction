<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * High availability: a main server ("primary") and a backup ("standby") whose
 * database is a streaming-replication copy. The root helper softswitch-ha does
 * the server work (file sync, cloning, promotion); this is the app's view of it.
 */
class HighAvailability
{
    /** The backup application name the main server sees (softswitch-ha clone). */
    public const BACKUP_APP = 'junction_backup';

    private static ?bool $standby = null;

    public static function role(): ?string
    {
        return config('softswitch.ha.role');
    }

    /** Is this the backup, with its read-only copy of the database? (Checked once per request.) */
    public static function isStandby(): bool
    {
        if (self::role() !== 'standby') {
            return false;
        }

        return self::$standby ??= (bool) rescue(fn () => DB::scalar('SELECT pg_is_in_recovery()'), false, false);
    }

    /** Tests: pretend to be (or not be) a backup. */
    public static function fakeStandby(?bool $standby): void
    {
        self::$standby = $standby;
    }

    /** Main server, every minute: the backup measures how far behind it is from this. */
    public static function beat(): void
    {
        DB::statement('INSERT INTO ha_heartbeat (id, beat_at) VALUES (1, now()) ON CONFLICT (id) DO UPDATE SET beat_at = now()');
    }

    /**
     * The main server's view of its backup: connected, and how far behind.
     *
     * @return array{connected: bool, address: ?string, state: ?string, lag: ?int}
     */
    public static function backup(): array
    {
        $row = rescue(fn () => DB::selectOne(
            'SELECT client_addr::text AS address, state, extract(epoch FROM replay_lag)::int AS lag FROM pg_stat_replication WHERE application_name = ? LIMIT 1',
            [self::BACKUP_APP],
        ), null, false);

        return [
            'connected' => $row !== null && $row->state === 'streaming',
            'address' => $row?->address,
            'state' => $row?->state,
            'lag' => $row === null ? null : (int) ($row->lag ?? 0),
        ];
    }

    /**
     * The backup's view of the main server: still streaming, and how old the
     * newest data is (the main server's heartbeat).
     *
     * @return array{streaming: bool, main: ?string, behind: ?int, last_message: ?string, healthy: bool}
     */
    public static function main(): array
    {
        $receiver = rescue(fn () => DB::selectOne('SELECT status, sender_host, last_msg_receipt_time FROM pg_stat_wal_receiver LIMIT 1'), null, false);
        $beat = rescue(fn () => DB::scalar('SELECT beat_at FROM ha_heartbeat WHERE id = 1'), null, false);
        $behind = $beat ? (int) CarbonImmutable::parse($beat)->diffInSeconds(now(), true) : null;
        $streaming = $receiver?->status === 'streaming';

        return [
            'streaming' => $streaming,
            'main' => $receiver?->sender_host ?? config('softswitch.ha.peer'),
            'behind' => $behind,
            'last_message' => $receiver?->last_msg_receipt_time ? CarbonImmutable::parse($receiver->last_msg_receipt_time)->toIso8601String() : null,
            'healthy' => $streaming && $behind !== null && $behind <= config('softswitch.ha.max_lag'),
        ];
    }

    /** What softswitch-ha last did (push, pull, promotion), from its status files. */
    public static function state(string $name): ?array
    {
        $file = self::stateDir()."/{$name}.json";
        try {
            return is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** The backup's "Make this the main server": softswitch-ha-promote.path picks this up as root. */
    public static function requestPromotion(int $userId): void
    {
        @mkdir(self::stateDir(), 0750, true);
        file_put_contents(self::stateDir().'/promote-request', json_encode(['by' => $userId, 'at' => now()->toIso8601String()]));
    }

    public static function promotionRequested(): bool
    {
        return is_file(self::stateDir().'/promote-request');
    }

    public static function stateDir(): string
    {
        return storage_path('app/ha');
    }

    /** Dashboard → High availability on the main server; null without a backup configured. */
    public static function dashboard(): ?array
    {
        if (self::role() !== 'primary') {
            return null;
        }

        return [...self::backup(), 'peer' => config('softswitch.ha.peer'), 'files' => self::state('push')];
    }
}
