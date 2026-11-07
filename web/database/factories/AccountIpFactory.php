<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\AccountIp;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountIp>
 */
class AccountIpFactory extends Factory
{
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'ip' => fake()->unique()->ipv4(),
            'tech_prefix' => '',
            'enabled' => true,
        ];
    }
}
