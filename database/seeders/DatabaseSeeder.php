<?php

namespace Database\Seeders;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Notifications\OrderCreatedNotification;
use Carbon\CarbonImmutable;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::factory()->admin()->create([
            'name' => 'Demo Admin',
            'email' => 'admin@example.com',
            'password' => 'password',
        ]);

        $customer = User::factory()->create([
            'name' => 'Demo Customer',
            'email' => 'customer@example.com',
            'password' => 'password',
            'role' => UserRole::Customer,
        ]);

        $otherCustomer = User::factory()->create([
            'name' => 'Other Customer',
            'email' => 'other-customer@example.com',
            'password' => 'password',
            'role' => UserRole::Customer,
        ]);

        [$hcmWarehouse, $hanoiWarehouse] = Warehouse::factory()
            ->count(2)
            ->sequence(
                ['code' => 'WH-HCM', 'name' => 'Ho Chi Minh Warehouse'],
                ['code' => 'WH-HN', 'name' => 'Ha Noi Warehouse'],
            )
            ->create();

        $products = Product::factory()
            ->count(7)
            ->sequence(
                ['sku' => 'IPHONE-15', 'name' => 'iPhone 15', 'price' => '19990000.00'],
                ['sku' => 'MACBOOK-AIR-M3', 'name' => 'MacBook Air M3', 'price' => '27990000.00'],
                ['sku' => 'AIRPODS-PRO-2', 'name' => 'AirPods Pro 2', 'price' => '5990000.00'],
                ['sku' => 'MAGIC-KEYBOARD', 'name' => 'Magic Keyboard', 'price' => '2990000.00'],
                ['sku' => 'HOMEPOD-MINI', 'name' => 'HomePod mini', 'price' => '2490000.00'],
                ['sku' => 'APPLE-PENCIL-2', 'name' => 'Apple Pencil 2', 'price' => '3290000.00'],
                ['sku' => 'LIMITED-STOCK-1', 'name' => 'Limited Stock Product', 'price' => '100000.00'],
            )
            ->create()
            ->keyBy('sku');

        $hcmInventory = [
            'IPHONE-15' => 95,
            'MACBOOK-AIR-M3' => 25,
            'AIRPODS-PRO-2' => 80,
            'MAGIC-KEYBOARD' => 10,
            'HOMEPOD-MINI' => 5,
            'APPLE-PENCIL-2' => 0,
            'LIMITED-STOCK-1' => 1,
        ];

        foreach ($products as $product) {
            Inventory::factory()->create([
                'warehouse_id' => $hcmWarehouse->id,
                'product_id' => $product->id,
                'quantity' => $hcmInventory[$product->sku],
            ]);

            Inventory::factory()->create([
                'warehouse_id' => $hanoiWarehouse->id,
                'product_id' => $product->id,
                'quantity' => 20,
            ]);
        }

        $recentCompletedProducts = collect([
            $products->get('IPHONE-15'),
            $products->get('AIRPODS-PRO-2'),
            $products->get('LIMITED-STOCK-1'),
        ]);
        $startDate = CarbonImmutable::create(2026, 9, 1, 8, 0, 0, 'UTC');
        $notificationOrder = null;

        foreach (range(1, 24) as $sequence) {
            $status = match ($sequence % 3) {
                0 => OrderStatus::Completed,
                1 => OrderStatus::Pending,
                2 => OrderStatus::Cancelled,
            };
            $product = match ($status) {
                OrderStatus::Completed => $recentCompletedProducts->get(intdiv($sequence, 3) % 3),
                OrderStatus::Pending => $products->get('MAGIC-KEYBOARD'),
                OrderStatus::Cancelled => $products->get('HOMEPOD-MINI'),
            };
            $paymentStatus = match ($status) {
                OrderStatus::Completed => PaymentStatus::Paid,
                OrderStatus::Pending => PaymentStatus::Pending,
                OrderStatus::Cancelled => PaymentStatus::Failed,
            };

            $order = $this->createOrder(
                sequence: $sequence,
                user: $customer,
                warehouse: $hcmWarehouse,
                product: $product,
                quantity: ($sequence % 2) + 1,
                status: $status,
                paymentStatus: $paymentStatus,
                createdAt: $startDate
                    ->addDays(($sequence - 1) % 7)
                    ->addMinutes($sequence),
            );

            $notificationOrder ??= $order;
        }

        foreach (range(101, 105) as $sequence) {
            $this->createOrder(
                sequence: $sequence,
                user: $otherCustomer,
                warehouse: $hanoiWarehouse,
                product: $products->get('IPHONE-15'),
                quantity: 1,
                status: OrderStatus::Completed,
                paymentStatus: PaymentStatus::Paid,
                createdAt: $startDate->addDays($sequence - 101)->addHours(2),
            );
        }

        $this->createOrder(
            sequence: 9001,
            user: $customer,
            warehouse: $hcmWarehouse,
            product: $products->get('MACBOOK-AIR-M3'),
            quantity: 1,
            status: OrderStatus::Completed,
            paymentStatus: PaymentStatus::Paid,
            createdAt: CarbonImmutable::create(2023, 9, 1, 8, 0, 0, 'UTC'),
        );

        $customer->notify(new OrderCreatedNotification($notificationOrder));
    }

    private function createOrder(
        int $sequence,
        User $user,
        Warehouse $warehouse,
        Product $product,
        int $quantity,
        OrderStatus $status,
        PaymentStatus $paymentStatus,
        CarbonImmutable $createdAt,
    ): Order {
        $lineTotal = bcmul($product->price, (string) $quantity, 2);
        $statusChangedAt = $status === OrderStatus::Pending
            ? $createdAt
            : $createdAt->addMinutes(5);

        $order = Order::factory()->create([
            'order_number' => sprintf('ORD-DEMO-%04d', $sequence),
            'user_id' => $user->id,
            'warehouse_id' => $warehouse->id,
            'status' => $status,
            'total_amount' => $lineTotal,
            'created_at' => $createdAt,
            'updated_at' => $statusChangedAt,
        ]);

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'unit_price' => $product->price,
            'line_total' => $lineTotal,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        Payment::factory()->create([
            'order_id' => $order->id,
            'amount' => $lineTotal,
            'status' => $paymentStatus,
            'created_at' => $createdAt,
            'updated_at' => $statusChangedAt,
        ]);

        OrderStatusHistory::factory()->create([
            'order_id' => $order->id,
            'from_status' => null,
            'to_status' => OrderStatus::Pending,
            'changed_by' => $user->id,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        if ($status !== OrderStatus::Pending) {
            OrderStatusHistory::factory()->create([
                'order_id' => $order->id,
                'from_status' => OrderStatus::Pending,
                'to_status' => $status,
                'changed_by' => $user->id,
                'created_at' => $statusChangedAt,
                'updated_at' => $statusChangedAt,
            ]);
        }

        return $order;
    }
}
