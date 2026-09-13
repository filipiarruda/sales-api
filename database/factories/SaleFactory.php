<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Sale;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sale>
 */
class SaleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'external_id' => fake()->unique()->bothify('SALE-#####'),
            'customer_id' => Customer::factory(),
            'amount' => fake()->numberBetween(100, 100000),
            'occurred_at' => fake()->dateTime(),
            'source' => 'webhook',
            'points_awarded' => null,
            'points_processed_at' => null,
        ];
    }
}
