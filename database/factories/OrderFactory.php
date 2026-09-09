<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Order> */
class OrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_number' => 'ORD-'.Str::ulid(),
            'user_id' => User::factory(),
            'warehouse_id' => Warehouse::factory(),
            'status' => OrderStatus::Pending,
            'total_amount' => '0.00',
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => OrderStatus::Completed,
        ]);
    }
}
