<?php

namespace App\Services;

use App\Models\Cdr;
use App\Models\Endpoint;
use App\Models\PbxDid;
use App\Models\Gateway;
use App\Models\Rate;
use App\Models\Trunk;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use SimpleXMLElement;

/**
 * Turns a FreeSWITCH mod_xml_cdr record (A-leg) into a rated CDR with its
 * vendor attempts, and releases the call's funds reservation.
 *
 * The routing script (route.lua) leaves softswitch_* channel variables on the
 * A-leg describing the customer, the sell/buy rates chosen and every carrier
 * attempt, so nothing has to be re-derived here.
 *
 * Idempotent: FreeSWITCH retries failed posts, and a CDR already posted to the
 * ledger is never changed.
 */
class CdrIngestor
{
    public function ingest(string $xml): ?Cdr
    {
        $doc = $this->parse($xml);
        $var = fn (string $name): ?string => isset($doc->variables->{$name}) && (string) $doc->variables->{$name} !== ''
            ? urldecode((string) $doc->variables->{$name})
            : null;
        $profile = $doc->callflow[0]->caller_profile ?? null;

        // The built-in self-test's fake customer and vendor legs (selftest
        // profile) are not traffic; only the carrier-profile leg is recorded.
        if ($var('sofia_profile_name') === 'selftest') {
            return null;
        }

        // The callee's leg of a PBX call: the caller's CDR covers the call.
        if ($var('softswitch_pbx_leg') === 'true') {
            return null;
        }

        // A vendor leg (B-leg) of one of our calls: only its timings are kept.
        if ($parent = $var('softswitch_parent_uuid')) {
            $this->legTimings($parent, (int) $var('softswitch_attempt_no'), $var);

            return null;
        }

        $uuid = $var('uuid');
        if (! $uuid || ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
            throw new InvalidArgumentException('CDR has no valid uuid.');
        }

        $time = fn (?string $uepoch) => $uepoch && (int) $uepoch > 0
            ? CarbonImmutable::createFromTimestampUTC(intdiv((int) $uepoch, 1_000_000))->addMicroseconds((int) $uepoch % 1_000_000)
            : null;
        $int = fn (?string $value) => $value !== null && is_numeric($value) ? (int) $value : null;

        $started = $time($var('start_uepoch')) ?? CarbonImmutable::now();
        $answered = $time($var('answer_uepoch'));
        $ended = $time($var('end_uepoch'));
        // Billable time is the bridged (answered) duration of the customer leg.
        $billsec = $answered ? max(0, (int) $var('billsec')) : 0;

        $attempts = json_decode($var('softswitch_attempts') ?? '[]', true);
        $attempts = is_array($attempts) ? $attempts : [];

        // References can disappear between the call and its CDR (a trunk deleted
        // mid-call); the CDR must still be stored, so keep only ids that exist.
        $trunkIds = Trunk::query()->whereIn('id', array_filter([$int($var('softswitch_trunk_id')), ...array_map(fn ($a) => $int((string) ($a['trunk'] ?? '')), $attempts)]))->pluck('id')->flip();
        $gatewayIds = Gateway::query()->whereIn('id', array_filter([$int($var('softswitch_gateway_id')), ...array_map(fn ($a) => $int((string) ($a['gateway'] ?? '')), $attempts)]))->pluck('id')->flip();
        $known = fn (?int $id, $set) => $id !== null && $set->has($id) ? $id : null;

        $trunkId = $known($int($var('softswitch_trunk_id')), $trunkIds);
        $sellRate = ($id = $int($var('softswitch_sell_rate_id'))) ? Rate::find($id) : null;
        $buyRate = ($id = $int($var('softswitch_buy_rate_id'))) ? Rate::find($id) : null;

        return DB::transaction(function () use ($uuid, $var, $profile, $int, $started, $answered, $ended, $billsec, $attempts, $trunkId, $sellRate, $buyRate, $known, $trunkIds, $gatewayIds) {
            DB::delete('DELETE FROM active_calls WHERE uuid = ?::uuid', [$uuid]);

            $existing = Cdr::query()->where('uuid', $uuid)->lockForUpdate()->first();
            if ($existing?->posted_at || $existing?->vendor_posted_at) {
                return $existing; // already on a ledger: never re-rate
            }

            $cdr = $existing ?? new Cdr(['uuid' => $uuid]);
            $direction = $var('softswitch_direction');
            $cdr->fill([
                'direction' => in_array($direction, Cdr::DIRECTIONS, true) ? $direction : 'carrier',
                'customer_account_id' => $int($var('softswitch_account_id')),
                'endpoint_id' => ($e = $int($var('softswitch_endpoint_id'))) && Endpoint::query()->whereKey($e)->exists() ? $e : null,
                'source_ip' => $this->ip((string) ($profile->network_addr ?? $var('network_addr') ?? '')),
                'caller' => mb_substr((string) ($profile->caller_id_number ?? $var('caller_id_number') ?? ''), 0, 64) ?: null,
                'callee' => mb_substr((string) ($profile->destination_number ?? $var('destination_number') ?? ''), 0, 64),
                'destination' => $var('softswitch_destination'),
                'sip_call_id' => ($cid = $var('sip_call_id')) !== null ? mb_substr($cid, 0, 255) : null,
                'did' => ($did = $var('softswitch_did')) && preg_match('/^\d{1,32}$/', $did) ? $did : null,
                'started_at' => $started,
                'answered_at' => $answered,
                'ended_at' => $ended,
                'billsec' => $billsec,
                // Customer-perceived post-dial delay: their INVITE to first ringback or answer.
                'pdd_ms' => $this->pdd($var('start_uepoch'), $var('progress_media_uepoch'), $var('progress_uepoch'), $var('answer_uepoch')),
                'sip_code' => $int($var('softswitch_sip_code')) ?? $int($var('sip_term_status')) ?? ($answered ? 200 : null),
                'hangup_cause' => $var('softswitch_reject_reason') ?? $var('hangup_cause'),
                'attempts' => count($attempts),
                'sell_prefix' => $sellRate?->prefix,
                'sell_destination' => $sellRate?->destination,
                'sell_rate' => $sellRate?->rate,
                'sell_cost' => $sellRate ? $sellRate->costFor($billsec) : null,
                // The carrier: the one that carried an outbound call, or the DID's provider for an inbound DID call.
                'vendor_account_id' => $trunkId && $answered
                    ? Trunk::query()->whereKey($trunkId)->value('account_id')
                    : ($direction === 'pbx_in' && $did ? PbxDid::query()->where('number', $did)->value('provider_account_id') : null),
                'trunk_id' => $answered ? $trunkId : null,
                'gateway_id' => $answered ? $known($int($var('softswitch_gateway_id')), $gatewayIds) : null,
                'buy_prefix' => $answered ? $buyRate?->prefix : null,
                'buy_rate' => $answered ? $buyRate?->rate : null,
                'buy_cost' => $answered && $buyRate ? $buyRate->costFor($billsec) : null,
                'rated_at' => now(),
            ]);
            // Billing profile: Canada/USA calls the customer's plan includes cost nothing.
            [$included, $sellCost] = app(IncludedMinutes::class)->rate($cdr, $sellRate, $billsec);
            $cdr->forceFill(['included_seconds' => $included, 'sell_cost' => $sellCost]);
            $cdr->save();

            $cdr->attemptLog()->delete();
            foreach (array_values($attempts) as $i => $a) {
                $cdr->attemptLog()->create([
                    'attempt' => $i + 1,
                    'trunk_id' => $known($int((string) ($a['trunk'] ?? '')), $trunkIds),
                    'gateway_id' => $known($int((string) ($a['gateway'] ?? '')), $gatewayIds),
                    'buy_prefix' => isset($a['buy_prefix']) ? (string) $a['buy_prefix'] : null,
                    'buy_rate' => isset($a['buy_rate']) && is_numeric($a['buy_rate']) ? $a['buy_rate'] : null,
                    'started_at' => isset($a['start']) ? CarbonImmutable::createFromTimestampUTC((int) $a['start']) : $started,
                    'answered_at' => ! empty($a['answered']) ? $answered : null,
                    'ended_at' => isset($a['end']) ? CarbonImmutable::createFromTimestampUTC((int) $a['end']) : null,
                    'sip_code' => $int(isset($a['sip']) ? (string) $a['sip'] : null),
                    'hangup_cause' => isset($a['cause']) ? mb_substr((string) $a['cause'], 0, 64) : null,
                ]);
            }

            // Vendor-leg timings (whether they arrived before or after this CDR;
            // kept for two days so a re-posted CDR merges them again).
            foreach (DB::table('cdr_leg_timings')->where('parent_uuid', $uuid)->get() as $t) {
                $this->applyTimings($cdr, (int) $t->attempt, (array) $t);
            }

            return $cdr;
        });
    }

    /** Store a vendor leg's exact timings on its attempt (or stage them until the parent CDR exists). */
    private function legTimings(string $parent, int $attempt, Closure $var): void
    {
        if (! preg_match('/^[0-9a-f-]{36}$/i', $parent) || $attempt < 1) {
            return;
        }
        $t = fn (?string $u) => $u && (int) $u > 0 ? CarbonImmutable::createFromTimestampUTC(intdiv((int) $u, 1_000_000))->addMicroseconds((int) $u % 1_000_000) : null;
        $timings = [
            'started_at' => $t($var('start_uepoch')),
            'progress_at' => $t($var('progress_media_uepoch')) ?? $t($var('progress_uepoch')),
            'answered_at' => $t($var('answer_uepoch')),
            'ended_at' => $t($var('end_uepoch')),
            // Carrier PDD: our INVITE to their first 180/183 (or answer).
            'pdd_ms' => $this->pdd($var('start_uepoch'), $var('progress_media_uepoch'), $var('progress_uepoch'), $var('answer_uepoch')),
            'sip_code' => is_numeric($var('sip_term_status')) ? (int) $var('sip_term_status') : null,
            'hangup_cause' => $var('hangup_cause'),
        ];

        DB::transaction(function () use ($parent, $attempt, $timings) {
            DB::table('cdr_leg_timings')->upsert([['parent_uuid' => $parent, 'attempt' => $attempt, ...$timings, 'created_at' => now()]],
                ['parent_uuid', 'attempt']);
            if ($cdr = Cdr::query()->where('uuid', $parent)->lockForUpdate()->first()) {
                $this->applyTimings($cdr, $attempt, $timings);
            }
        });
    }

    private function applyTimings(Cdr $cdr, int $attempt, array $t): void
    {
        $cdr->attemptLog()->where('attempt', $attempt)->update(array_filter([
            'started_at' => $t['started_at'] ?? null,
            'answered_at' => $t['answered_at'] ?? null,
            'ended_at' => $t['ended_at'] ?? null,
            'pdd_ms' => $t['pdd_ms'] ?? null,
        ], fn ($v) => $v !== null));
    }

    /** Milliseconds from start to the first of: early media, ringing, answer. */
    private function pdd(?string $start, ?string ...$events): ?int
    {
        $start = (int) $start;
        $first = collect($events)->map(fn ($e) => (int) $e)->filter(fn ($e) => $e > 0)->min();
        if ($start <= 0 || ! $first || $first < $start) {
            return null;
        }

        return (int) min(intdiv($first - $start, 1000), 600_000);
    }

    private function parse(string $xml): SimpleXMLElement
    {
        // No network access, no entity expansion (PHP 8 never loads external entities).
        $previous = libxml_use_internal_errors(true);
        try {
            $doc = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        } finally {
            libxml_use_internal_errors($previous);
        }
        if ($doc === false || $doc->getName() !== 'cdr') {
            throw new InvalidArgumentException('Not a FreeSWITCH XML CDR.');
        }

        return $doc;
    }

    private function ip(string $value): ?string
    {
        return filter_var($value, FILTER_VALIDATE_IP) ? $value : null;
    }
}
