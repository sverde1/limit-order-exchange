<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'symbol' => 'BTC',
            'side' => Order::SIDE_BUY,
            'price' => '10.00000000',
            'amount' => '1.000000000000000000',
            'status' => Order::STATUS_OPEN,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
