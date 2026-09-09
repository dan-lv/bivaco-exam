<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OrderStatusHistory> */
class OrderStatusHistoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'from_status' => null,
            'to_status' => OrderStatus::Pending,
            'changed_by' => User::factory(),
        ];
    }
}
