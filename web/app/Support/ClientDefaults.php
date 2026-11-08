<?php

namespace App\Support;

use App\Enums\AccountStatus;
use App\Enums\BillingType;
use App\Models\RateDeck;
use App\Models\RoutingPlan;
use Illuminate\Validation\Rule;

/**
 * Settings → Client defaults: what a new customer account starts with on the
 * "New customer" form (tariff, LCR group, limits, billing...), so the admin
 * only types the customer's details. Stored as one app setting.
 */
final class ClientDefaults
{
    public const KEY = 'client.defaults';

    /** Built-in values when nothing has been saved. */
    public const BUILT_IN = [
        'status' => 'active',
        'currency' => Currencies::DEFAULT,
        'billing_type' => 'prepaid',
        'credit_limit' => '0',
        'low_balance_threshold' => null,
        'timezone' => 'UTC',
        'sell_rate_deck_id' => null,
        'routing_plan_id' => null,
        'max_channels' => null,
        'max_cps' => null,
    ];

    /** @return array<string, mixed> the saved defaults; a deleted tariff or LCR group is dropped */
    public static function get(): array
    {
        $saved = Settings::get(self::KEY, []);
        $values = [...self::BUILT_IN, ...array_intersect_key(is_array($saved) ? $saved : [], self::BUILT_IN)];

        if ($values['sell_rate_deck_id'] && ! RateDeck::query()->whereKey($values['sell_rate_deck_id'])->where('kind', 'tariff')->exists()) {
            $values['sell_rate_deck_id'] = null;
        }
        if ($values['routing_plan_id'] && ! RoutingPlan::query()->whereKey($values['routing_plan_id'])->exists()) {
            $values['routing_plan_id'] = null;
        }

        return $values;
    }

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(AccountStatus::class)],
            'currency' => ['required', 'string', Rule::in(array_keys(Currencies::ALL))],
            'billing_type' => ['required', Rule::enum(BillingType::class)],
            'credit_limit' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'low_balance_threshold' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'timezone' => ['required', 'timezone:all'],
            'sell_rate_deck_id' => ['nullable', 'integer', Rule::exists('rate_decks', 'id')->where('kind', 'tariff')],
            'routing_plan_id' => ['nullable', 'integer', 'exists:routing_plans,id'],
            'max_channels' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'max_cps' => ['nullable', 'numeric', 'min:0.1', 'max:10000'],
        ];
    }

    /** @param  array<string, mixed>  $data  validated */
    public static function save(array $data): void
    {
        $values = [];
        foreach (array_keys(self::BUILT_IN) as $field) {
            $value = $data[$field] ?? null;
            $values[$field] = $value === '' ? null : $value;
        }
        foreach (['sell_rate_deck_id', 'routing_plan_id', 'max_channels'] as $int) {
            $values[$int] = $values[$int] === null ? null : (int) $values[$int];
        }
        foreach (['credit_limit', 'low_balance_threshold', 'max_cps'] as $number) {
            $values[$number] = $values[$number] === null ? null : (string) $values[$number];
        }
        Settings::put([self::KEY => $values]);
    }
}
