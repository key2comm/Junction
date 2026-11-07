<?php

namespace Database\Factories;

use App\Models\Account;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'code' => strtoupper(fake()->unique()->bothify('???###')),
            'is_customer' => true,
            'is_vendor' => false,
            'status' => 'active',
            'currency' => 'USD',
            'billing_type' => 'prepaid',
            'credit_limit' => 0,
        ];
    }

    public function vendor(): static
    {
        return $this->state(['is_customer' => false, 'is_vendor' => true]);
    }

    public function both(): static
    {
        return $this->state(['is_customer' => true, 'is_vendor' => true]);
    }

    public function postpaid(string $creditLimit = '1000'): static
    {
        return $this->state(['billing_type' => 'postpaid', 'credit_limit' => $creditLimit]);
    }
}
