<?php

namespace App\Services;

use App\Models\Cdr;
use App\Models\Gateway;
use App\Models\Rate;
use App\Models\Trunk;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use SimpleXMLElement;

/**
 * Turns a FreeSWITCH mod_xml_cdr record (A-leg) into a rated CDR with its
 * vendor attempts, and releases the call's funds reservation.
 *
 * The routing script (route.lua) leaves softswitch_* channel variables on the
 * A-leg describing the customer, the sell/buy rates chosen and every vendor
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
            if ($existing?->posted_at) {
                return $existing; // already billed: never re-rate
            }

            $cdr = $existing ?? new Cdr(['uuid' => $uuid]);
            $cdr->fill([
                'customer_account_id' => $int($var('softswitch_account_id')),
                'source_ip' => $this->ip((string) ($profile->network_addr ?? $var('network_addr') ?? '')),
                'caller' => mb_substr((string) ($profile->caller_id_number ?? $var('caller_id_number') ?? ''), 0, 64) ?: null,
                'callee' => mb_substr((string) ($profile->destination_number ?? $var('destination_number') ?? ''), 0, 64),
                'destination' => $var('softswitch_destination'),
                'started_at' => $started,
                'answered_at' => $answered,
                'ended_at' => $ended,
                'billsec' => $billsec,
                'sip_code' => $int($var('softswitch_sip_code')) ?? $int($var('sip_term_status')) ?? ($answered ? 200 : null),
                'hangup_cause' => $var('softswitch_reject_reason') ?? $var('hangup_cause'),
                'attempts' => count($attempts),
                'sell_prefix' => $sellRate?->prefix,
                'sell_rate' => $sellRate?->rate,
                'sell_cost' => $sellRate ? $sellRate->costFor($billsec) : null,
                'vendor_account_id' => $trunkId && $answered ? Trunk::query()->whereKey($trunkId)->value('account_id') : null,
                'trunk_id' => $answered ? $trunkId : null,
                'gateway_id' => $answered ? $known($int($var('softswitch_gateway_id')), $gatewayIds) : null,
                'buy_prefix' => $answered ? $buyRate?->prefix : null,
                'buy_rate' => $answered ? $buyRate?->rate : null,
                'buy_cost' => $answered && $buyRate ? $buyRate->costFor($billsec) : null,
                'rated_at' => now(),
            ]);
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

            return $cdr;
        });
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
