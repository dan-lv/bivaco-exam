<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\ProductStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeactivateStaleProductsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_deactivates_stale_products_in_chunks(): void
    {
        $user = User::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $recentlySold = Product::factory()->create();
        $soldLongAgo = Product::factory()->create();
        $neverSold = Product::factory()->create();
        $onlyPending = Product::factory()->create();
        $alreadyInactive = Product::factory()->inactive()->create();

        $recentOrder = Order::factory()->completed()->create([
            'user_id' => $user->id,
            'warehouse_id' => $warehouse->id,
            'created_at' => '2025-09-09 00:00:00',
        ]);
        $oldOrder = Order::factory()->completed()->create([
            'user_id' => $user->id,
            'warehouse_id' => $warehouse->id,
            'created_at' => '2023-09-09 00:00:00',
        ]);
        $pendingOrder = Order::factory()->create([
            'user_id' => $user->id,
            'warehouse_id' => $warehouse->id,
            'status' => OrderStatus::Pending,
            'created_at' => '2025-09-09 00:00:00',
        ]);

        OrderItem::factory()->create([
            'order_id' => $recentOrder->id,
            'product_id' => $recentlySold->id,
        ]);
        OrderItem::factory()->create([
            'order_id' => $oldOrder->id,
            'product_id' => $soldLongAgo->id,
        ]);
        OrderItem::factory()->create([
            'order_id' => $pendingOrder->id,
            'product_id' => $onlyPending->id,
        ]);

        $this->artisan('products:deactivate-stale', [
            '--before' => '2024-09-09',
            '--chunk' => 1,
        ])->expectsOutputToContain('Deactivated 3 stale products.')
            ->assertSuccessful();

        $this->assertSame(ProductStatus::Active, $recentlySold->refresh()->status);
        $this->assertSame(ProductStatus::Inactive, $soldLongAgo->refresh()->status);
        $this->assertSame(ProductStatus::Inactive, $neverSold->refresh()->status);
        $this->assertSame(ProductStatus::Inactive, $onlyPending->refresh()->status);
        $this->assertSame(ProductStatus::Inactive, $alreadyInactive->refresh()->status);

        $this->artisan('products:deactivate-stale', [
            '--before' => '2024-09-09',
            '--chunk' => 1,
        ])->expectsOutputToContain('Deactivated 0 stale products.')
            ->assertSuccessful();
    }

    public function test_it_rejects_invalid_options(): void
    {
        $this->artisan('products:deactivate-stale', ['--chunk' => 0])->assertFailed();
        $this->artisan('products:deactivate-stale', ['--before' => 'not-a-date'])->assertFailed();
    }
}
