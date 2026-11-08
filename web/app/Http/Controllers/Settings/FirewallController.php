<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Jobs\ApplyFirewallPolicy;
use App\Jobs\RefreshFirewallStatus;
use App\Jobs\UnbanAddress;
use App\Models\FirewallEntry;
use App\Services\FirewallPolicy;
use App\Services\FirewallSync;
use App\Support\Dns;
use App\Support\IpNet;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Settings -> Firewall: whitelist, blacklist, service lockdown and fail2ban's
 * automatic bans. The firewall itself is changed by queued jobs (the root
 * helper only runs from the queue worker); see FirewallPolicy for the
 * confirm-or-rollback safety net.
 */
class FirewallController extends Controller
{
    public const DURATIONS = ['' => null, '1h' => 'PT1H', '1d' => 'P1D', '7d' => 'P7D', '30d' => 'P30D'];

    public function index(Request $request): Response
    {
        FirewallPolicy::finalizeExpired();
        $ip = $request->ip();
        $trusted = FirewallPolicy::trustedNets();
        $status = Cache::get(FirewallPolicy::STATUS);
        $offset = $status['utc_offset'] ?? '+0000';
        $time = fn (?string $t) => $t ? CarbonImmutable::parse("{$t} {$offset}")->utc()->toIso8601String() : null;

        $entries = FirewallEntry::query()->with('user:id,name')->orderBy('value')->get()->map(fn (FirewallEntry $e) => [
            ...$e->only(['id', 'list', 'value', 'reason', 'resolved', 'expires_at', 'resolved_at', 'created_at']),
            'hostname' => $e->isHostname(),
            'user' => $e->user?->name,
        ]);

        return Inertia::render('settings/Firewall', [
            'allow' => $entries->where('list', 'allow')->values(),
            'block' => $entries->where('list', 'block')->values(),
            'lockdown' => FirewallPolicy::lockdown(),
            'services' => FirewallPolicy::SERVICES,
            'pending' => FirewallPolicy::pending(),
            'error' => Cache::get(FirewallPolicy::ERROR),
            'you' => ['ip' => $ip, 'trusted' => FirewallPolicy::isTrusted($ip)],
            'status' => $status === null ? null : [
                'checked_at' => $status['checked_at'] ?? null,
                'ssh_clients' => array_map(fn ($c) => ['ip' => $c, 'trusted' => IpNet::inAny($c, $trusted)], $status['ssh_clients'] ?? []),
                'bans' => array_map(fn ($b) => [
                    'ip' => $b['ip'], 'jail' => $b['jail'], 'banned_at' => $time($b['banned_at'] ?? null), 'until' => $time($b['until'] ?? null),
                ], $status['bans'] ?? []),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'list' => ['required', 'in:allow,block'],
            'value' => ['required', 'string', 'max:255'],
            'reason' => [$request->input('list') === 'block' ? 'required' : 'nullable', 'string', 'max:255'],
            'duration' => ['nullable', 'in:'.implode(',', array_filter(array_keys(self::DURATIONS)))],
        ], ['reason.required' => 'Give a reason for the ban.']);

        $entry = $this->add($request, $data['list'], $data['value'], $data['reason'] ?? null, $data['duration'] ?? null);
        Inertia::flash('toast', ['type' => 'success', 'message' => ($entry->list === 'allow' ? 'Whitelisted ' : 'Blacklisted ').$entry->value.'. The firewall updates in a few seconds.']);

        return to_route('firewall.index');
    }

    public function destroy(Request $request, FirewallEntry $entry): RedirectResponse
    {
        $this->refuseWhilePending('value');
        $lockdown = FirewallPolicy::lockdown();
        $ip = $request->ip();

        if ($entry->list === 'allow') {
            // Don't let an admin cut off their own access to the admin pages.
            if ($lockdown['web'] && IpNet::inAny($ip, $entry->nets())) {
                $others = FirewallEntry::query()->where('list', 'allow')->whereKeyNot($entry->id)->get()->flatMap->nets()->all();
                if (! IpNet::inAny($ip, $others) && ! in_array($ip, ['127.0.0.1', '::1'], true)) {
                    throw ValidationException::withMessages(['value' => "Admin login is locked to the whitelist and {$entry->value} is how you ({$ip}) are allowed in. Turn that lockdown off first, or whitelist your address another way."]);
                }
            }
            $entry->delete();
            FirewallPolicy::forgetTrusted();
            if ($lockdown['ssh'] || $lockdown['pbx']) {
                // Could cut someone off: apply with the rollback timer; if not confirmed, SSH/PBX lockdown is turned off.
                FirewallPolicy::begin($lockdown, [...$lockdown, 'ssh' => false, 'pbx' => false], "Remove {$entry->value} from the whitelist", true);
                Inertia::flash('toast', ['type' => 'warning', 'message' => 'Removed. Check you can still connect, then confirm the change above.']);

                return to_route('firewall.index');
            }
        } else {
            $entry->delete();
        }

        ApplyFirewallPolicy::dispatch();
        Inertia::flash('toast', ['type' => 'success', 'message' => "Removed {$entry->value} from the ".($entry->list === 'allow' ? 'whitelist.' : 'blacklist.')]);

        return to_route('firewall.index');
    }

    public function lockdown(Request $request): RedirectResponse
    {
        $data = $request->validate(['ssh' => ['boolean'], 'web' => ['boolean'], 'pbx' => ['boolean']]);
        $this->refuseWhilePending('lockdown');
        $current = FirewallPolicy::lockdown();
        $new = ['ssh' => $request->boolean('ssh'), 'web' => $request->boolean('web'), 'pbx' => $request->boolean('pbx')];
        $on = array_keys(array_filter($new, fn ($v, $k) => $v && ! $current[$k], ARRAY_FILTER_USE_BOTH));

        if ($on === []) {
            // Only turning things off: can't lock anyone out.
            FirewallPolicy::set($new);
            Inertia::flash('toast', ['type' => 'success', 'message' => 'Lockdown saved.']);

            return to_route('firewall.index');
        }

        $trusted = FirewallPolicy::trustedNets();
        if ($trusted === []) {
            throw ValidationException::withMessages(['lockdown' => 'Add the addresses that should keep access to the whitelist first.']);
        }
        if (in_array('web', $on, true) && ! FirewallPolicy::isTrusted($request->ip())) {
            throw ValidationException::withMessages(['lockdown' => "Your address ({$request->ip()}) isn't on the whitelist, so locking admin login would lock you out. Whitelist it first."]);
        }
        if (in_array('ssh', $on, true)) {
            $clients = Cache::get(FirewallPolicy::STATUS)['ssh_clients'] ?? [];
            $untrusted = array_values(array_filter($clients, fn ($c) => ! IpNet::inAny($c, $trusted)));
            if ($clients !== [] && count($untrusted) === count($clients)) {
                throw ValidationException::withMessages(['lockdown' => 'Someone is connected over SSH right now from '.implode(', ', $untrusted).", which isn't on the whitelist. Whitelist the addresses you use for SSH first."]);
            }
        }

        $labels = array_map(fn ($s) => FirewallPolicy::SERVICES[$s], $on);
        $firewall = in_array('ssh', $on, true) || in_array('pbx', $on, true) || $new['ssh'] !== $current['ssh'] || $new['pbx'] !== $current['pbx'];
        FirewallPolicy::begin($new, $current, 'Lock down '.implode(', ', $labels), $firewall);
        Inertia::flash('toast', ['type' => 'warning', 'message' => 'Applied. Check you can still connect, then confirm the change above.']);

        return to_route('firewall.index');
    }

    public function confirm(): RedirectResponse
    {
        if (! FirewallPolicy::confirm()) {
            throw ValidationException::withMessages(['confirm' => 'There is no change waiting for confirmation (it may already have been undone).']);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Firewall change kept.']);

        return to_route('firewall.index');
    }

    public function undo(): RedirectResponse
    {
        FirewallPolicy::finalizeExpired(force: true);
        Cache::forget(FirewallPolicy::ERROR);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Change undone.']);

        return to_route('firewall.index');
    }

    public function refresh(): RedirectResponse
    {
        RefreshFirewallStatus::dispatch();
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Refreshing the ban list…']);

        return to_route('firewall.index');
    }

    public function unban(Request $request): RedirectResponse
    {
        $data = $request->validate(['ip' => ['required', 'ip']]);
        UnbanAddress::dispatch($data['ip']);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Unbanning {$data['ip']}…"]);

        return to_route('firewall.index');
    }

    /** A fail2ban ban made permanent: blacklisted, then lifted from fail2ban (the blacklist covers it). */
    public function blacklistBan(Request $request): RedirectResponse
    {
        $data = $request->validate(['ip' => ['required', 'ip'], 'jail' => ['required', 'string', 'max:64']]);
        $jail = ['sshd' => 'SSH', 'freeswitch' => 'SIP'][$data['jail']] ?? $data['jail'];
        $this->add($request, 'block', $data['ip'], "Banned by fail2ban ({$jail} login failures)", null);
        UnbanAddress::dispatch($data['ip']);
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$data['ip']} is blacklisted."]);

        return to_route('firewall.index');
    }

    /** Validate and save one whitelist/blacklist entry, then sync the firewall. */
    private function add(Request $request, string $list, string $value, ?string $reason, ?string $duration): FirewallEntry
    {
        $this->refuseWhilePending('value');
        $value = strtolower(trim($value));
        $net = IpNet::normalize($value);
        if ($net !== null) {
            $value = $net;
            $nets = [$net];
        } elseif (IpNet::isHostname($value)) {
            $nets = array_values(array_filter(array_map(fn ($ip) => IpNet::normalize($ip), Dns::resolve($value))));
            if ($nets === []) {
                throw ValidationException::withMessages(['value' => "{$value} doesn't resolve to any address."]);
            }
        } else {
            throw ValidationException::withMessages(['value' => 'Enter an IP address (1.2.3.4), a network (1.2.3.0/24) or a hostname (office.example.com).']);
        }
        foreach ($nets as $n) {
            if (IpNet::tooBroad($n)) {
                throw ValidationException::withMessages(['value' => "{$n} is too broad (IPv4 networks can be /8 or smaller, IPv6 /32 or smaller)."]);
            }
        }
        if (FirewallEntry::query()->where('list', $list)->where('value', $value)->exists()) {
            throw ValidationException::withMessages(['value' => "{$value} is already on the ".($list === 'allow' ? 'whitelist.' : 'blacklist.')]);
        }

        $other = FirewallEntry::query()->where('list', $list === 'allow' ? 'block' : 'allow')->get();
        foreach ($other as $o) {
            foreach ($o->nets() as $existing) {
                foreach ($nets as $n) {
                    if (IpNet::overlaps($n, $existing)) {
                        throw ValidationException::withMessages(['value' => "{$value} overlaps {$o->value}, which is on the ".($list === 'allow' ? 'blacklist' : 'whitelist').'. Remove it there first.']);
                    }
                }
            }
        }

        if ($list === 'block') {
            $ip = $request->ip();
            foreach ($nets as $n) {
                if (IpNet::inAny($ip, [$n])) {
                    throw ValidationException::withMessages(['value' => "That would block your own address ({$ip})."]);
                }
            }
            // Customers' and carriers' addresses are managed on their accounts and trunks.
            try {
                $peers = app(FirewallSync::class)->allowlist();
            } catch (Throwable) {
                $peers = [];
            }
            foreach ($nets as $n) {
                foreach ($peers as $peer) {
                    if (IpNet::normalize($peer) !== null && IpNet::overlaps($n, IpNet::normalize($peer))) {
                        throw ValidationException::withMessages(['value' => "{$value} covers {$peer}, a customer or carrier address. Disable it on the account or trunk instead."]);
                    }
                }
            }
        }

        $entry = FirewallEntry::create([
            'list' => $list,
            'value' => $value,
            'reason' => $reason,
            'expires_at' => $list === 'block' && $duration ? now()->add(new \DateInterval(self::DURATIONS[$duration])) : null,
            'resolved' => $net === null ? $nets : null,
            'resolved_at' => $net === null ? now() : null,
            'user_id' => $request->user()?->id,
        ]);
        FirewallPolicy::forgetTrusted();
        ApplyFirewallPolicy::dispatch();

        return $entry;
    }

    private function refuseWhilePending(string $field): void
    {
        if (FirewallPolicy::pending() !== null) {
            throw ValidationException::withMessages([$field => 'A firewall change is waiting for confirmation. Keep it or undo it first.']);
        }
    }
}
