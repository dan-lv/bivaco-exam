<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_only_sees_their_orders_with_pagination_meta(): void
    {
        $customer = User::factory()->create();
        $otherCustomer = User::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $ownOrder = Order::factory()->create([
            'user_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
        ]);
        Order::factory()->create([
            'user_id' => $otherCustomer->id,
            'warehouse_id' => $warehouse->id,
        ]);
        Sanctum::actingAs($customer);

        Model::preventLazyLoading();

        try {
            $this->getJson('/api/orders?per_page=10')
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $ownOrder->id)
                ->assertJsonPath('meta.current_page', 1)
                ->assertJsonPath('meta.per_page', 10)
                ->assertJsonPath('meta.total', 1);
        } finally {
            Model::preventLazyLoading(false);
        }
    }

    public function test_admin_can_filter_orders_by_status_keyword_and_date(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $iphone = Product::factory()->create([
            'sku' => 'IPHONE-15',
            'name' => 'iPhone 15',
        ]);
        $macbook = Product::factory()->create([
            'sku' => 'MACBOOK-AIR',
            'name' => 'MacBook Air',
        ]);
        $matchingOrder = Order::factory()->completed()->create([
            'user_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'created_at' => '2026-09-03 12:00:00',
        ]);
        $wrongStatus = Order::factory()->create([
            'user_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'status' => OrderStatus::Pending,
            'created_at' => '2026-09-03 12:00:00',
        ]);
        $wrongKeyword = Order::factory()->completed()->create([
            'user_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'created_at' => '2026-09-03 12:00:00',
        ]);
        OrderItem::factory()->create([
            'order_id' => $matchingOrder->id,
            'product_id' => $iphone->id,
        ]);
        OrderItem::factory()->create([
            'order_id' => $wrongStatus->id,
            'product_id' => $iphone->id,
        ]);
        OrderItem::factory()->create([
            'order_id' => $wrongKeyword->id,
            'product_id' => $macbook->id,
        ]);
        Sanctum::actingAs($admin);

        $this->getJson(
            '/api/orders?status=completed&keyword=iphone&from_date=2026-09-01&to_date=2026-09-07'
        )->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $matchingOrder->id)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_order_filters_are_validated(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson(
            '/api/orders?per_page=101&status=unknown&from_date=2026-09-08&to_date=2026-09-07'
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page', 'status', 'to_date']);
    }
}
