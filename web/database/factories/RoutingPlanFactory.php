<?php

namespace Database\Factories;

use App\Models\RoutingPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoutingPlan>
 */
class RoutingPlanFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'mode' => 'lcr',
            'max_attempts' => 3,
        ];
    }
}
