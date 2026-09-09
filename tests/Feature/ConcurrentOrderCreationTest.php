<?php

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ConcurrentOrderCreationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_only_one_concurrent_order_succeeds_when_inventory_is_one(): void
    {
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $product = Product::factory()->create();
        $inventory = Inventory::factory()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 1,
        ]);

        $startAt = sprintf('%.6F', microtime(true) + 0.5);
        $firstProcess = $this->orderProcess(
            $firstUser,
            $warehouse,
            $product,
            $startAt,
        );
        $secondProcess = $this->orderProcess(
            $secondUser,
            $warehouse,
            $product,
            $startAt,
        );

        $firstProcess->start();
        $secondProcess->start();
        $firstProcess->wait();
        $secondProcess->wait();

        $this->assertTrue($firstProcess->isSuccessful(), $firstProcess->getErrorOutput());
        $this->assertTrue($secondProcess->isSuccessful(), $secondProcess->getErrorOutput());

        $outcomes = [
            trim($firstProcess->getOutput()),
            trim($secondProcess->getOutput()),
        ];
        sort($outcomes);

        $this->assertSame(['conflict', 'created'], $outcomes);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_items', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('order_status_histories', 1);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame(0, $inventory->fresh()->quantity);
    }

    private function orderProcess(
        User $user,
        Warehouse $warehouse,
        Product $product,
        string $startAt,
    ): Process {
        return new Process([
            PHP_BINARY,
            base_path('tests/Support/create-order-worker.php'),
            (string) $user->id,
            (string) $warehouse->id,
            (string) $product->id,
            '1',
            $startAt,
        ], base_path(), timeout: 10);
    }
}
