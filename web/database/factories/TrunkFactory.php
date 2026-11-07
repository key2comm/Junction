<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\RateDeck;
use App\Models\Trunk;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Trunk>
 */
class TrunkFactory extends Factory
{
    public function definition(): array
    {
        return [
            'account_id' => Account::factory()->vendor(),
            'name' => fake()->unique()->word(),
            'rate_deck_id' => RateDeck::factory(),
            'enabled' => true,
            'tech_prefix' => '',
            'strip_digits' => 0,
        ];
    }
}
