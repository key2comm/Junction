<?php

namespace App\Services;

use App\Jobs\ApplyFirewallPolicy;
use App\Models\FirewallEntry;
use App\Support\IpNet;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Settings -> Firewall state: the whitelist/blacklist (FirewallEntry) and the
 * service lockdown (SSH, admin web login, PBX SIP).
 *
 * Safety: a change that could lock people out (turning a lockdown on, or
 * removing a whitelist entry while one is on) is "pending" until an admin
 * confirms it. If nobody confirms within CONFIRM_SECONDS, the firewall helper
 * rolls the firewall back on its own and the app falls back to the saved
 * "fallback" lockdown (for whitelist removals: SSH/PBX lockdown off).
 */
final class FirewallPolicy
{
    public const LOCKDOWN = 'firewall.lockdown';

    public const PENDING = 'firewall.pending';

    /** Last status from the helper (fail2ban bans, SSH clients). */
    public const STATUS = 'softswitch.firewall.status';

    /** Last error from a firewall job, shown on the page. */
    public const ERROR = 'softswitch.firewall.error';

    private const TRUSTED = 'softswitch.firewall.trusted';

    /** How long the admin has to confirm (the helper's timer gets the same, from when it applies). */
    public const CONFIRM_SECONDS = 180;

    /** After the deadline, wait this long for the helper's own rollback before re-syncing. */
    public const GRACE_SECONDS = 30;

    public const SERVICES = ['ssh' => 'SSH', 'web' => 'Admin web login', 'pbx' => 'PBX phones (SIP)'];

    /** @return array{ssh: bool, web: bool, pbx: bool} the lockdown in force (an unconfirmed change past its deadline doesn't count) */
    public static function lockdown(): array
    {
        $pending = Settings::get(self::PENDING);
        if (is_array($pending) && self::expired($pending)) {
            return self::flags($pending['fallback'] ?? []);
        }

        return self::flags(Settings::get(self::LOCKDOWN, []));
    }

    /** @return array{until: string, what: string, helper: bool, fallback: array}|null a change waiting for confirmation */
    public static function pending(): ?array
    {
        $pending = Settings::get(self::PENDING);

        return is_array($pending) && ! self::expired($pending) ? $pending : null;
    }

    /**
     * Save a lockdown that needs confirming. $helper: the firewall itself
     * changes (SSH/PBX), so it is applied with the helper's rollback timer.
     */
    public static function begin(array $lockdown, array $fallback, string $what, bool $helper): void
    {
        Settings::put([
            self::LOCKDOWN => self::flags($lockdown),
            self::PENDING => [
                'until' => CarbonImmutable::now()->addSeconds(self::CONFIRM_SECONDS)->toIso8601String(),
                'what' => $what,
                'helper' => $helper,
                'fallback' => self::flags($fallback),
            ],
        ]);
        Cache::forget(self::ERROR);
        if ($helper) {
            ApplyFirewallPolicy::dispatch(confirm: true);
        }
    }

    /** Save a lockdown that can't lock anyone out (turning things off) and apply it. */
    public static function set(array $lockdown): void
    {
        Settings::put([self::LOCKDOWN => self::flags($lockdown)]);
        ApplyFirewallPolicy::dispatch();
    }

    /** The admin confirmed the pending change. Returns false if there was none (or it timed out). */
    public static function confirm(): bool
    {
        $pending = self::pending();
        if ($pending === null) {
            return false;
        }
        Settings::put([self::PENDING => null]);
        if ($pending['helper'] ?? false) {
            \App\Jobs\ConfirmFirewallPolicy::dispatch($pending['fallback']);
        }

        return true;
    }

    /**
     * Undo a pending change: the saved fallback lockdown is restored and the
     * firewall re-synced (after the helper's rollback, if it hasn't run yet).
     * $force: now, not only once the deadline has passed.
     */
    public static function finalizeExpired(bool $force = false): bool
    {
        $pending = Settings::get(self::PENDING);
        if (! is_array($pending)) {
            return false;
        }
        $past = CarbonImmutable::parse($pending['until'])->addSeconds(self::GRACE_SECONDS)->isPast();
        if (! $force && ! $past) {
            return false;
        }
        Settings::put([self::LOCKDOWN => self::flags($pending['fallback'] ?? []), self::PENDING => null]);
        Cache::put(self::ERROR, "The firewall change \"{$pending['what']}\" wasn't confirmed in time, so it was undone.", now()->addDay());
        ApplyFirewallPolicy::dispatch(rollbackFirst: (bool) ($pending['helper'] ?? false));

        return true;
    }

    /** @return list<string> whitelisted IPs/networks (hostnames as resolved at the last sync) */
    public static function trustedNets(): array
    {
        return Cache::remember(self::TRUSTED, 300, fn () => FirewallEntry::query()->where('list', 'allow')->orderBy('id')->get()
            ->flatMap(fn (FirewallEntry $e) => $e->nets())->unique()->values()->all());
    }

    /** @return list<string> */
    public static function blockedNets(): array
    {
        return FirewallEntry::query()->where('list', 'block')->active()->orderBy('id')->get()
            ->flatMap(fn (FirewallEntry $e) => $e->nets())->unique()->values()->all();
    }

    public static function forgetTrusted(): void
    {
        Cache::forget(self::TRUSTED);
    }

    public static function isTrusted(?string $ip): bool
    {
        if ($ip === null) {
            return false;
        }
        if (in_array($ip, ['127.0.0.1', '::1'], true)) {
            return true;
        }

        return IpNet::inAny($ip, self::trustedNets());
    }

    /** Input for "softswitch-firewall policy". */
    public static function helperInput(): string
    {
        $lines = [];
        foreach (self::trustedNets() as $net) {
            $lines[] = 'trust '.$net;
        }
        foreach (self::blockedNets() as $net) {
            $lines[] = 'block '.$net;
        }
        foreach (['ssh', 'pbx'] as $service) {
            if (self::lockdown()[$service]) {
                $lines[] = 'lock '.$service;
            }
        }

        return implode("\n", $lines)."\n";
    }

    /** @return array{ssh: bool, web: bool, pbx: bool} */
    private static function flags(mixed $value): array
    {
        $value = is_array($value) ? $value : [];

        return ['ssh' => (bool) ($value['ssh'] ?? false), 'web' => (bool) ($value['web'] ?? false), 'pbx' => (bool) ($value['pbx'] ?? false)];
    }

    private static function expired(array $pending): bool
    {
        return ! isset($pending['until']) || CarbonImmutable::parse($pending['until'])->isPast();
    }
}
