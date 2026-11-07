<?php

namespace Database\Factories;

use App\Models\RoutingPlan;
use App\Models\RoutingPlanRoute;
use App\Models\Trunk;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoutingPlanRoute>
 */
class RoutingPlanRouteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'routing_plan_id' => RoutingPlan::factory(),
            'prefix' => '',
            'trunk_id' => Trunk::factory(),
            'priority' => 1,
            'weight' => 100,
            'enabled' => true,
        ];
    }
}
