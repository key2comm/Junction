<?php

namespace Database\Factories;

use App\Models\BlockedPrefix;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BlockedPrefix>
 */
class BlockedPrefixFactory extends Factory
{
    public function definition(): array
    {
        return [
            'account_id' => null,
            'prefix' => (string) fake()->numberBetween(1, 9999),
            'reason' => 'Fraud risk',
        ];
    }
}
