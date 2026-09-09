<?php

namespace App\Actions;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ProductStatus;
use App\Exceptions\OrderConflictException;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateOrderAction
{
    /**
     * @param  array<int, array{product_id: int, quantity: int}>  $items
     */
    public function execute(User $user, int $warehouseId, array $items): Order
    {
        $items = collect($items)->sortBy('product_id')->values();

        return DB::transaction(function () use ($user, $warehouseId, $items): Order {
            $warehouse = Warehouse::query()->find($warehouseId);

            if ($warehouse === null) {
                throw new OrderConflictException('The selected warehouse is no longer available.');
            }

            $productIds = $items->pluck('product_id')->all();
            $products = Product::query()
                ->whereIn('id', $productIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($items as $item) {
                $product = $products->get($item['product_id']);

                if ($product === null || $product->status !== ProductStatus::Active) {
                    throw new OrderConflictException(
                        'A selected product is no longer available.',
                        $item['product_id']
                    );
                }
            }

            $inventories = Inventory::query()
                ->where('warehouse_id', $warehouse->id)
                ->whereIn('product_id', $productIds)
                ->orderBy('product_id')
                ->lockForUpdate()
                ->get()
                ->keyBy('product_id');

            foreach ($items as $item) {
                $inventory = $inventories->get($item['product_id']);

                if ($inventory === null) {
                    throw new OrderConflictException(
                        'The selected product is not stocked at this warehouse.',
                        $item['product_id']
                    );
                }

                if ($inventory->quantity < $item['quantity']) {
                    throw new OrderConflictException(
                        'Insufficient inventory for the selected product.',
                        $item['product_id']
                    );
                }
            }

            $totalAmount = '0.00';
            $lineItems = [];

            foreach ($items as $item) {
                $product = $products->get($item['product_id']);
                $lineTotal = bcmul($product->price, (string) $item['quantity'], 2);
                $totalAmount = bcadd($totalAmount, $lineTotal, 2);

                $lineItems[] = [
                    'product_id' => $product->id,
                    'quantity' => $item['quantity'],
                    'unit_price' => $product->price,
                    'line_total' => $lineTotal,
                ];
            }

            $order = Order::query()->create([
                'order_number' => 'ORD-'.Str::ulid(),
                'user_id' => $user->id,
                'warehouse_id' => $warehouse->id,
                'status' => OrderStatus::Pending,
                'total_amount' => $totalAmount,
            ]);

            $order->items()->createMany($lineItems);

            foreach ($items as $item) {
                $inventory = $inventories->get($item['product_id']);
                $inventory->quantity -= $item['quantity'];
                $inventory->save();
            }

            $order->payment()->create([
                'amount' => $totalAmount,
                'status' => PaymentStatus::Pending,
            ]);

            $order->statusHistories()->create([
                'from_status' => null,
                'to_status' => OrderStatus::Pending,
                'changed_by' => $user->id,
            ]);

            return $order->load(['user', 'warehouse', 'items.product', 'payment']);
        }, 3);
    }
}
