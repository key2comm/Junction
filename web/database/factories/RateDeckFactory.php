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
            'kind' => 'carrier',
            'currency' => 'USD',
        ];
    }

    /** A customer tariff (sell rates). */
    public function tariff(): static
    {
        return $this->state(['kind' => 'tariff']);
    }
}
