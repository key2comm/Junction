<?php

namespace Database\Factories;

use App\Models\Rate;
use App\Models\RateDeck;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Rate>
 */
class RateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'rate_deck_id' => RateDeck::factory(),
            'prefix' => (string) fake()->numberBetween(1, 999),
            'destination' => fake()->country(),
            'rate' => fake()->randomFloat(6, 0.001, 0.2),
            'connect_fee' => 0,
            'initial_increment' => 60,
            'increment' => 60,
            'effective_from' => now()->subDay(),
            'effective_to' => null,
        ];
    }
}
