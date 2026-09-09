<?php

namespace Tests\Feature;

use App\Events\OrderCreated;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Notifications\OrderCreatedNotification;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class OrderCreatedNotificationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_order_creation_notifies_the_customer_after_commit(): void
    {
        $user = User::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $product = Product::factory()->create(['price' => '12.50']);
        Inventory::factory()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 2,
        ]);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/orders', [
            'warehouse_id' => $warehouse->id,
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2],
            ],
        ])->assertCreated();

        $notification = $user->notifications()->sole();

        $this->assertSame(OrderCreatedNotification::class, $notification->type);
        $this->assertSame($response->json('data.id'), $notification->data['order_id']);
        $this->assertSame($response->json('data.order_number'), $notification->data['order_number']);
        $this->assertSame('pending', $notification->data['status']);
        $this->assertSame('25.00', $notification->data['total_amount']);
    }

    public function test_notification_is_not_sent_when_the_transaction_rolls_back(): void
    {
        Notification::fake();
        $order = Order::factory()->create();

        try {
            DB::transaction(function () use ($order): void {
                OrderCreated::dispatch($order);

                throw new RuntimeException('Force the transaction to roll back.');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Force the transaction to roll back.', $exception->getMessage());
        }

        Notification::assertNothingSent();
    }
}
