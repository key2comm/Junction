<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Endpoint;
use App\Models\Gateway;
use App\Services\Freeswitch\EventSocket;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Calls in progress, from FreeSWITCH ("show channels" plus each call's own
 * channel variables over one ESL connection), enriched from the database.
 *
 * Every call that reaches the switch is listed, whichever port it came in on:
 * carrier traffic (carrier profile), devices and PBXs on the PBX port and
 * built-in PBX phones (internal profile), and DIDs from carriers. The
 * self-test's own caller legs are left out.
 *
 * The carrier leg of a call is found from the variables lcr.lua tags it with
 * (softswitch_parent_uuid / softswitch_gateway_id), else from the caller leg's
 * Other-Leg-Unique-ID, so nothing depends on "show calls" pairing the legs.
 *
 * Only the queue worker / scheduler talks to FreeSWITCH (SELinux keeps PHP-FPM
 * off the event socket), so pages read a snapshot that the scheduler refreshes
 * every 5 seconds while someone is watching.
 */
class LiveCalls
{
    public const SNAPSHOT = 'softswitch.live.snapshot';

    public const WATCHING = 'softswitch.live.watching';

    public const DIRECTIONS = [
        'carrier' => 'Outbound',
        'pbx_out' => 'Outbound (device/PBX)',
        'pbx_in' => 'DID inbound',
        'pbx_local' => 'PBX internal',
    ];

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
        $json = json_decode($this->esl->api('show channels as json'), true);
        $rows = array_values(array_filter($json['rows'] ?? [], fn ($r) => preg_match('/^[0-9a-f-]{36}$/i', (string) ($r['uuid'] ?? ''))
            && str_starts_with((string) ($r['name'] ?? ''), 'sofia/') && ! str_starts_with((string) $r['name'], 'sofia/selftest/')));
        $callers = array_values(array_filter($rows, fn ($r) => ($r['direction'] ?? '') === 'inbound'));
        $others = array_values(array_filter($rows, fn ($r) => ($r['direction'] ?? '') === 'outbound'));

        // One connection: each caller leg's variables, and which call each outbound leg belongs to.
        $commands = [];
        foreach ($callers as $r) {
            $commands[] = "uuid_dump {$r['uuid']} json";
        }
        foreach ($others as $r) {
            $commands[] = "uuid_getvar {$r['uuid']} softswitch_parent_uuid";
            $commands[] = "uuid_getvar {$r['uuid']} softswitch_gateway_id";
        }
        $out = $commands ? $this->esl->apiMany($commands) : [];

        $dumps = [];
        foreach ($callers as $i => $r) {
            $d = json_decode((string) ($out[$i] ?? ''), true);
            $dumps[$r['uuid']] = is_array($d) ? $d : [];
        }
        $legs = [];         // caller uuid => its carrier/device leg
        $byUuid = [];
        foreach ($others as $j => $r) {
            $parent = trim((string) ($out[count($callers) + $j * 2] ?? ''));
            $gateway = trim((string) ($out[count($callers) + $j * 2 + 1] ?? ''));
            $leg = ['row' => $r, 'gateway_id' => ctype_digit($gateway) ? (int) $gateway : null];
            $byUuid[$r['uuid']] = $leg;
            if (preg_match('/^[0-9a-f-]{36}$/i', $parent)) {
                $legs[$parent] = $leg; // the attempt in progress (a failed one is already gone)
            }
        }
        foreach ($callers as $r) {
            $other = (string) ($dumps[$r['uuid']]['Other-Leg-Unique-ID'] ?? '');
            if (! isset($legs[$r['uuid']]) && isset($byUuid[$other])) {
                $legs[$r['uuid']] = $byUuid[$other];
            }
        }

        $var = fn (string $uuid, string $name) => ($v = $dumps[$uuid]["variable_{$name}"] ?? null) !== null && $v !== '' ? (string) $v : null;
        $uuids = array_column($callers, 'uuid');
        $reservations = DB::table('active_calls')->whereIn('uuid', $uuids)->get()->keyBy('uuid');
        $accountIds = [];
        foreach ($callers as $r) {
            $id = $var($r['uuid'], 'softswitch_account_id') ?? $reservations[$r['uuid']]->account_id ?? null;
            $accountIds[$r['uuid']] = ctype_digit((string) $id) ? (int) $id : null;
        }
        $accounts = Account::query()->whereIn('id', array_filter($accountIds))->get(['id', 'code', 'name', 'currency'])->keyBy('id');
        $endpointIds = array_filter(array_map(fn ($r) => ctype_digit((string) $var($r['uuid'], 'softswitch_endpoint_id')) ? (int) $var($r['uuid'], 'softswitch_endpoint_id') : null, $callers));
        $endpoints = Endpoint::query()->whereIn('id', $endpointIds)->get(['id', 'name'])->keyBy('id');
        $gateways = Gateway::query()->with('trunk:id,name,account_id', 'trunk.account:id,code,name')
            ->whereIn('id', array_filter(array_column($legs, 'gateway_id')))->get(['id', 'name', 'label', 'trunk_id'])->keyBy('id');

        $now = now()->getTimestamp();
        $calls = [];
        foreach ($callers as $r) {
            $uuid = $r['uuid'];
            $dump = $dumps[$uuid];
            $answeredUs = (int) ($dump['Caller-Channel-Answered-Time'] ?? 0);
            $answered = $answeredUs > 0 ? intdiv($answeredUs, 1_000_000) : 0;
            $leg = $legs[$uuid] ?? null;
            $legState = $leg['row']['callstate'] ?? '';
            $reservation = $reservations[$uuid] ?? null;
            $account = $accountIds[$uuid] ? ($accounts[$accountIds[$uuid]] ?? null) : null;
            $gateway = $leg && $leg['gateway_id'] ? ($gateways[$leg['gateway_id']] ?? null) : null;
            $endpointId = $var($uuid, 'softswitch_endpoint_id');
            $endpoint = $endpointId !== null && ctype_digit($endpointId) ? ($endpoints[(int) $endpointId] ?? null) : null;
            $direction = $var($uuid, 'softswitch_direction') ?? (str_starts_with((string) $r['name'], 'sofia/carrier/') || $endpoint ? 'carrier' : null);
            $talking = $answered > 0 ? max(0, $now - $answered) : null;
            $ringing = in_array($legState, ['RINGING', 'EARLY'], true) || in_array($r['callstate'] ?? '', ['RINGING', 'EARLY'], true);

            $calls[] = [
                'uuid' => $uuid,
                'started_at' => gmdate('Y-m-d\TH:i:s\Z', (int) ($r['created_epoch'] ?? $now)),
                'state' => $answered > 0 ? 'answered' : ($ringing ? 'ringing' : 'routing'),
                'direction' => $direction,
                'direction_label' => self::DIRECTIONS[$direction] ?? null,
                'caller' => $var($uuid, 'effective_caller_id_number') ?? ($r['cid_num'] ?? null),
                'dialled' => $r['dest'] ?? null,
                'sent_as' => $leg['row']['dest'] ?? null,
                'source' => $r['ip_addr'] ?? ($dump['Caller-Network-Addr'] ?? null),
                'customer' => $account?->only(['id', 'code', 'name']),
                'endpoint' => $endpoint?->only(['id', 'name']),
                'matched_by' => $var($uuid, 'softswitch_matched_by'),
                'did' => $var($uuid, 'softswitch_did'),
                'vendor' => $gateway?->trunk?->account?->code,
                'vendor_name' => $gateway?->trunk?->account?->name,
                'trunk' => $gateway?->trunk?->only(['id', 'name']),
                'gateway' => $gateway ? ($gateway->label ?: $gateway->name) : null,
                'codec' => $var($uuid, 'read_codec'),
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
