<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Gateway;
use App\Services\Freeswitch\EventSocket;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Calls in progress, from FreeSWITCH ("show calls" plus two channel variables
 * per call over one ESL connection), enriched from the database.
 *
 * Only the queue worker / scheduler talks to FreeSWITCH (SELinux keeps PHP-FPM
 * off the event socket), so pages read a snapshot that the scheduler refreshes
 * every 5 seconds while someone is watching.
 */
class LiveCalls
{
    public const SNAPSHOT = 'softswitch.live.snapshot';

    public const WATCHING = 'softswitch.live.watching';

    public function __construct(private EventSocket $esl) {}

    /** Called by pages and the API: keep snapshots coming for the next 30 seconds. */
    public static function watch(): void
    {
        Cache::put(self::WATCHING, true, now()->addSeconds(30));
    }

    /** @return array{at: ?string, calls: list<array<string, mixed>>} */
    public static function snapshot(): array
    {
        return Cache::get(self::SNAPSHOT, ['at' => null, 'calls' => []]);
    }

    /** @return array{at: string, calls: list<array<string, mixed>>} */
    public function refresh(): array
    {
        $json = json_decode($this->esl->api('show calls as json'), true);
        // Customer legs: inbound on the carrier profile. (Vendor legs are their b-legs.)
        $legs = array_values(array_filter($json['rows'] ?? [], fn ($r) => ($r['direction'] ?? '') === 'inbound'
            && str_starts_with((string) ($r['name'] ?? ''), 'sofia/carrier/') && preg_match('/^[0-9a-f-]{36}$/i', (string) ($r['uuid'] ?? ''))));

        // Per call: the live answer time (channel time variables are only filled
        // in at hangup, so ask uuid_dump, and only for answered calls) and the
        // gateway route.lua tagged on the vendor leg. 'uptime' is a cheap no-op.
        $commands = [];
        foreach ($legs as $r) {
            $bridged = ! empty($r['b_uuid']) && preg_match('/^[0-9a-f-]{36}$/i', $r['b_uuid']);
            $answered = $bridged && ($r['callstate'] ?? '') === 'ACTIVE' && ($r['b_callstate'] ?? '') === 'ACTIVE';
            $commands[] = $answered ? "uuid_dump {$r['uuid']} json" : 'uptime';
            $commands[] = $bridged ? "uuid_getvar {$r['b_uuid']} softswitch_gateway_id" : 'uptime';
        }
        $vars = $commands ? $this->esl->apiMany($commands) : [];

        $uuids = array_column($legs, 'uuid');
        $reservations = DB::table('active_calls')->whereIn('uuid', $uuids)->get()->keyBy('uuid');
        $accounts = Account::query()->whereIn('id', $reservations->pluck('account_id'))->get(['id', 'code', 'name', 'currency'])->keyBy('id');
        $gatewayIds = [];
        foreach ($legs as $i => $r) {
            $g = trim($vars[$i * 2 + 1] ?? '');
            $gatewayIds[$i] = ctype_digit($g) && ! empty($r['b_uuid']) ? (int) $g : null;
        }
        $gateways = Gateway::query()->with('trunk:id,name,account_id', 'trunk.account:id,code')
            ->whereIn('id', array_filter($gatewayIds))->get(['id', 'name', 'trunk_id'])->keyBy('id');

        $now = now()->getTimestamp();
        $calls = [];
        foreach ($legs as $i => $r) {
            $dump = json_decode((string) ($vars[$i * 2] ?? ''), true);
            $answeredUs = is_array($dump) ? (int) ($dump['Caller-Channel-Answered-Time'] ?? 0) : 0;
            $answered = $answeredUs > 0 ? intdiv($answeredUs, 1_000_000) : 0;
            $reservation = $reservations[$r['uuid']] ?? null;
            $account = $reservation ? ($accounts[$reservation->account_id] ?? null) : null;
            $gateway = $gatewayIds[$i] ? ($gateways[$gatewayIds[$i]] ?? null) : null;
            $talking = $answered > 0 ? max(0, $now - $answered) : null;

            $calls[] = [
                'uuid' => $r['uuid'],
                'started_at' => gmdate('Y-m-d\TH:i:s\Z', (int) ($r['created_epoch'] ?? $now)),
                'state' => $answered > 0 ? 'answered' : (in_array($r['b_callstate'] ?? '', ['RINGING', 'EARLY'], true) ? 'ringing' : 'routing'),
                'caller' => $r['cid_num'] ?? null,
                'dialled' => $r['dest'] ?? null,
                'sent_as' => $r['b_dest'] ?? null,
                'customer' => $account?->only(['id', 'code', 'name']),
                'vendor' => $gateway?->trunk?->account?->code,
                'trunk' => $gateway?->trunk?->only(['id', 'name']),
                'gateway' => $gateway?->name,
                'talking_seconds' => $talking,
                'max_duration' => $reservation?->max_duration ? (int) $reservation->max_duration : null,
                'remaining_seconds' => $talking !== null && $reservation?->max_duration ? max(0, (int) $reservation->max_duration - $talking) : null,
                'reserved' => $reservation?->reserved,
                'currency' => $account?->currency,
            ];
        }
        usort($calls, fn ($a, $b) => strcmp($a['started_at'], $b['started_at']));

        $snapshot = ['at' => gmdate('Y-m-d\TH:i:s\Z', $now), 'calls' => $calls];
        Cache::put(self::SNAPSHOT, $snapshot, now()->addMinutes(5));

        return $snapshot;
    }
}
