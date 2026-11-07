<?php

namespace Database\Factories;

use App\Models\Gateway;
use App\Models\Trunk;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Gateway>
 */
class GatewayFactory extends Factory
{
    public function definition(): array
    {
        return [
            'trunk_id' => Trunk::factory(),
            'name' => fake()->unique()->slug(2),
            'host' => fake()->ipv4(),
            'port' => 5060,
            'transport' => 'udp',
            'register' => false,
            'enabled' => true,
            'priority' => 1,
            'weight' => 100,
        ];
    }
}
