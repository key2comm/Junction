<?php

namespace Database\Factories;

use App\Models\RateDeck;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RateDeck>
 */
class RateDeckFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(3, true),
            'currency' => 'USD',
        ];
    }
}
