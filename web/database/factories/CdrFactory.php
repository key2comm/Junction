<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Cdr;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cdr>
 */
class CdrFactory extends Factory
{
    public function definition(): array
    {
        return [
            'uuid' => fake()->uuid(),
            'customer_account_id' => Account::factory(),
            'source_ip' => fake()->ipv4(),
            'caller' => fake()->numerify('1##########'),
            'callee' => fake()->numerify('44##########'),
            'started_at' => now()->subMinutes(5),
            'billsec' => 0,
            'attempts' => 1,
        ];
    }
}
