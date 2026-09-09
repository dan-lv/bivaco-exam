<?php

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_place_an_order_successfully(): void
    {
        $user = User::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $firstProduct = Product::factory()->create(['price' => '10.50']);
        $secondProduct = Product::factory()->create(['price' => '20.00']);
        Inventory::factory()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $firstProduct->id,
            'quantity' => 5,
        ]);
        Inventory::factory()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $secondProduct->id,
            'quantity' => 3,
        ]);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/orders', [
            'warehouse_id' => $warehouse->id,
            'items' => [
                ['product_id' => $firstProduct->id, 'quantity' => 2],
                ['product_id' => $secondProduct->id, 'quantity' => 1],
            ],
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.total_amount', '41.00')
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.payment.status', 'pending');

        $orderId = $response->json('data.id');

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'user_id' => $user->id,
            'warehouse_id' => $warehouse->id,
            'status' => 'pending',
            'total_amount' => '41.00',
        ]);
        $this->assertDatabaseCount('order_items', 2);
        $this->assertDatabaseHas('payments', [
            'order_id' => $orderId,
            'amount' => '41.00',
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $orderId,
            'from_status' => null,
            'to_status' => 'pending',
            'changed_by' => $user->id,
        ]);
        $this->assertDatabaseHas('inventories', [
            'warehouse_id' => $warehouse->id,
            'product_id' => $firstProduct->id,
            'quantity' => 3,
        ]);
        $this->assertDatabaseHas('inventories', [
            'warehouse_id' => $warehouse->id,
            'product_id' => $secondProduct->id,
            'quantity' => 2,
        ]);
    }

    public function test_entire_order_is_rolled_back_when_one_item_has_insufficient_inventory(): void
    {
        $user = User::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $availableProduct = Product::factory()->create();
        $insufficientProduct = Product::factory()->create();
        Inventory::factory()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $availableProduct->id,
            'quantity' => 10,
        ]);
        Inventory::factory()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $insufficientProduct->id,
            'quantity' => 1,
        ]);
        Sanctum::actingAs($user);

        $this->postJson('/api/orders', [
            'warehouse_id' => $warehouse->id,
            'items' => [
                ['product_id' => $availableProduct->id, 'quantity' => 2],
                ['product_id' => $insufficientProduct->id, 'quantity' => 2],
            ],
        ])->assertConflict()
            ->assertJsonPath('product_id', $insufficientProduct->id);

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('order_status_histories', 0);
        $this->assertDatabaseHas('inventories', [
            'product_id' => $availableProduct->id,
            'quantity' => 10,
        ]);
        $this->assertDatabaseHas('inventories', [
            'product_id' => $insufficientProduct->id,
            'quantity' => 1,
        ]);
    }

    public function test_order_is_rejected_when_product_does_not_exist(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $warehouse = Warehouse::factory()->create();

        $this->postJson('/api/orders', [
            'warehouse_id' => $warehouse->id,
            'items' => [
                ['product_id' => 999999, 'quantity' => 1],
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.product_id']);

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_order_is_not_created_when_inventory_is_one_and_quantity_is_two(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $warehouse = Warehouse::factory()->create();
        $product = Product::factory()->create();
        Inventory::factory()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 1,
        ]);

        $this->postJson('/api/orders', [
            'warehouse_id' => $warehouse->id,
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2],
            ],
        ])->assertConflict();

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseHas('inventories', [
            'product_id' => $product->id,
            'quantity' => 1,
        ]);
    }

    public function test_duplicate_products_are_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $warehouse = Warehouse::factory()->create();
        $product = Product::factory()->create();

        $this->postJson('/api/orders', [
            'warehouse_id' => $warehouse->id,
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['items.1.product_id']);
    }
}
