<?php

namespace App\Services;

use App\Models\Account;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * PHP access to the routing engine (the softswitch_route() PostgreSQL function,
 * which FreeSWITCH also calls), for the admin UI's route tester and for tests.
 */
class Router
{
    public const REASONS = [
        'unknown_customer' => 'The account is not a customer.',
        'account_inactive' => 'The account is suspended or closed.',
        'invalid_number' => 'The number has no digits (or more than 32).',
        'blocked_destination' => 'The destination prefix is blocked.',
        'no_sell_rate' => "The customer's sell rate deck has no rate for this destination.",
        'no_routing_plan' => 'The customer has no routing plan.',
        'insufficient_funds' => 'Not enough funds for the minimum billable duration.',
        'no_route' => 'No enabled trunk with a gateway and a buy rate is routed for this destination.',
        'negative_margin' => 'Every routed vendor costs more than the sell rate.',
    ];

    /**
     * @return array{status: string, reason: ?string, message: ?string, destination: ?string,
     *     sell_prefix: ?string, sell_rate: ?string, max_duration: ?int, attempts: list<array>}
     */
    public function route(Account $account, string $number, ?string $uuid = null, ?DateTimeInterface $at = null): array
    {
        $rows = DB::select(
            'SELECT * FROM softswitch_route(?, ?, ?::uuid, ?::timestamptz)',
            [$account->id, $number, $uuid, ($at ?? now())->format(DATE_ATOM)],
        );
        $first = $rows[0];

        $attempts = [];
        foreach ($rows as $row) {
            if ($row->status !== 'ok') {
                continue;
            }
            $attempts[$row->attempt] ??= [
                'attempt' => $row->attempt,
                'trunk_id' => $row->trunk_id,
                'dial_number' => $row->dial_number,
                'buy_rate_id' => $row->buy_rate_id,
                'buy_prefix' => $row->buy_prefix,
                'buy_rate' => $row->buy_rate,
                'gateways' => [],
            ];
            $attempts[$row->attempt]['gateways'][] = ['id' => $row->gateway_id, 'name' => $row->gateway_name];
        }

        return [
            'status' => $first->status,
            'reason' => $first->reason,
            'message' => $first->reason ? (self::REASONS[$first->reason] ?? $first->reason) : null,
            'destination' => $first->destination,
            'sell_rate_id' => $first->sell_rate_id,
            'sell_prefix' => $first->sell_prefix,
            'sell_rate' => $first->sell_rate,
            'max_duration' => $first->max_duration,
            'attempts' => array_values($attempts),
        ];
    }

    /** Which customer account a call from $ip to $number belongs to, if any. */
    public function identify(string $ip, string $number): ?object
    {
        return DB::selectOne('SELECT * FROM softswitch_identify(?::inet, ?)', [$ip, $number]);
    }

    public function release(string $uuid): void
    {
        DB::select('SELECT softswitch_release(?::uuid)', [$uuid]);
    }
}
