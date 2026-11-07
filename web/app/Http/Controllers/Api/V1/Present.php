<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Account;
use App\Models\Cdr;
use App\Models\CdrAttempt;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Rate;
use App\Models\RateDeck;
use App\Models\RateImport;
use App\Models\RoutingPlan;
use App\Models\RoutingPlanRoute;
use App\Models\Trunk;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * JSON representations for API v1 (documented in resources/api/openapi.json).
 * Money is always a decimal string; times are ISO 8601 UTC.
 */
final class Present
{
    private static function t(?DateTimeInterface $at): ?string
    {
        return $at ? CarbonImmutable::instance($at)->utc()->format('Y-m-d\TH:i:s\Z') : null;
    }

    public static function account(Account $a, bool $customerView = false): array
    {
        $base = [
            'id' => $a->id,
            'code' => $a->code,
            'name' => $a->name,
            'status' => $a->status->value,
            'currency' => $a->currency,
            'billing_type' => $a->billing_type->value,
            'credit_limit' => $a->credit_limit,
            'balance' => $a->balance,
            'available_funds' => $a->availableFundsNow(),
            'timezone' => $a->timezone,
            'billing_email' => $a->billing_email,
        ];
        if ($customerView) {
            return $base;
        }

        return [
            ...$base,
            'is_customer' => $a->is_customer,
            'is_vendor' => $a->is_vendor,
            'vendor_balance' => $a->vendor_balance,
            'low_balance_threshold' => $a->low_balance_threshold,
            'noc_email' => $a->noc_email,
            'sell_rate_deck_id' => $a->sell_rate_deck_id,
            'routing_plan_id' => $a->routing_plan_id,
            'max_channels' => $a->max_channels,
            'max_cps' => $a->max_cps,
            'notes' => $a->notes,
            'created_at' => self::t($a->created_at),
            'updated_at' => self::t($a->updated_at),
        ];
    }

    public static function ip($ip): array
    {
        return ['id' => $ip->id, 'ip' => $ip->ip, 'tech_prefix' => $ip->tech_prefix, 'description' => $ip->description, 'enabled' => $ip->enabled];
    }

    public static function ledgerEntry($e): array
    {
        return ['id' => $e->id, 'type' => $e->type->value ?? $e->type, 'amount' => $e->amount, 'balance_after' => $e->balance_after,
            'description' => $e->description, 'effective_at' => self::t($e->effective_at), 'created_at' => self::t($e->created_at)];
    }

    public static function deck(RateDeck $d): array
    {
        return ['id' => $d->id, 'name' => $d->name, 'currency' => $d->currency, 'description' => $d->description,
            'created_at' => self::t($d->created_at), 'updated_at' => self::t($d->updated_at)];
    }

    public static function rate(Rate $r): array
    {
        return ['id' => $r->id, 'prefix' => $r->prefix, 'destination' => $r->destination, 'rate' => $r->rate, 'connect_fee' => $r->connect_fee,
            'initial_increment' => $r->initial_increment, 'increment' => $r->increment,
            'effective_from' => self::t($r->effective_from), 'effective_to' => self::t($r->effective_to)];
    }

    public static function import(RateImport $i): array
    {
        return ['id' => $i->id, 'rate_deck_id' => $i->rate_deck_id, 'filename' => $i->filename, 'mode' => $i->mode, 'status' => $i->status,
            'effective_from' => self::t($i->effective_from), 'stats' => $i->stats, 'errors' => $i->errors,
            'created_at' => self::t($i->created_at), 'finished_at' => self::t($i->finished_at)];
    }

    public static function trunk(Trunk $t, bool $withGateways = false): array
    {
        return [
            'id' => $t->id, 'vendor_id' => $t->account_id, 'name' => $t->name, 'rate_deck_id' => $t->rate_deck_id, 'enabled' => $t->enabled,
            'max_channels' => $t->max_channels, 'max_cps' => $t->max_cps, 'tech_prefix' => $t->tech_prefix, 'strip_digits' => $t->strip_digits,
            ...($withGateways ? ['gateways' => $t->gateways->map(fn ($g) => self::gateway($g))->all()] : []),
        ];
    }

    /** Gateway passwords are write-only. */
    public static function gateway(Gateway $g): array
    {
        return ['id' => $g->id, 'trunk_id' => $g->trunk_id, 'name' => $g->name, 'host' => $g->host, 'port' => $g->port,
            'transport' => $g->transport->value, 'username' => $g->username, 'has_password' => $g->getRawOriginal('password') !== null,
            'register' => $g->register, 'enabled' => $g->enabled, 'priority' => $g->priority, 'weight' => $g->weight];
    }

    public static function plan(RoutingPlan $p, bool $withRoutes = false): array
    {
        return [
            'id' => $p->id, 'name' => $p->name, 'mode' => $p->mode->value, 'max_attempts' => $p->max_attempts, 'description' => $p->description,
            'quality_routing' => $p->quality_routing, 'quality_min_asr' => $p->quality_min_asr, 'quality_max_pdd_ms' => $p->quality_max_pdd_ms,
            'quality_min_attempts' => $p->quality_min_attempts,
            ...($withRoutes ? ['routes' => $p->routes->map(fn ($r) => self::route($r))->all()] : []),
        ];
    }

    public static function route(RoutingPlanRoute $r): array
    {
        return ['id' => $r->id, 'prefix' => $r->prefix, 'trunk_id' => $r->trunk_id, 'priority' => $r->priority, 'weight' => $r->weight, 'enabled' => $r->enabled];
    }

    public static function cdr(Cdr $c, bool $customerView = false): array
    {
        $base = [
            'id' => $c->id, 'uuid' => $c->uuid, 'customer_id' => $c->customer_account_id,
            'caller' => $c->caller, 'callee' => $c->callee, 'destination' => $c->destination,
            'started_at' => self::t($c->started_at), 'answered_at' => self::t($c->answered_at), 'ended_at' => self::t($c->ended_at),
            'billsec' => $c->billsec, 'sip_code' => $c->sip_code, 'hangup_cause' => $c->hangup_cause,
            'sell_prefix' => $c->sell_prefix, 'sell_destination' => $c->sell_destination, 'sell_rate' => $c->sell_rate, 'sell_cost' => $c->sell_cost,
        ];
        if ($customerView) {
            return $base; // customers never see vendors or our costs
        }

        return [...$base, 'source_ip' => $c->source_ip, 'attempts' => $c->attempts, 'vendor_id' => $c->vendor_account_id, 'trunk_id' => $c->trunk_id,
            'gateway_id' => $c->gateway_id, 'buy_prefix' => $c->buy_prefix, 'buy_rate' => $c->buy_rate, 'buy_cost' => $c->buy_cost,
            'margin' => $c->margin(), 'posted_at' => self::t($c->posted_at)];
    }

    public static function attempt(CdrAttempt $a): array
    {
        return ['attempt' => $a->attempt, 'trunk_id' => $a->trunk_id, 'gateway_id' => $a->gateway_id, 'buy_prefix' => $a->buy_prefix,
            'buy_rate' => $a->buy_rate, 'started_at' => self::t($a->started_at), 'answered' => $a->answered_at !== null,
            'sip_code' => $a->sip_code, 'hangup_cause' => $a->hangup_cause];
    }

    public static function invoice(Invoice $i, bool $withLines = false): array
    {
        return [
            'id' => $i->id, 'number' => $i->number, 'customer_id' => $i->account_id, 'status' => $i->status,
            'period_start' => self::t($i->period_start), 'period_end' => self::t($i->period_end), 'timezone' => $i->timezone, 'currency' => $i->currency,
            'billing_type' => $i->billing_type, 'opening_balance' => $i->opening_balance, 'usage_total' => $i->usage_total,
            'payments_total' => $i->payments_total, 'adjustments_total' => $i->adjustments_total, 'closing_balance' => $i->closing_balance,
            'amount_due' => $i->amount_due, 'calls' => $i->calls, 'billsec' => $i->billsec,
            'issued_at' => self::t($i->issued_at), 'due_at' => self::t($i->due_at), 'voided_at' => self::t($i->voided_at),
            ...($withLines ? ['lines' => $i->lines->map(fn ($l) => ['prefix' => $l->prefix, 'destination' => $l->destination, 'calls' => $l->calls,
                'billsec' => $l->billsec, 'rate' => $l->rate, 'amount' => $l->amount])->all()] : []),
        ];
    }
}
