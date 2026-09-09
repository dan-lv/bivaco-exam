<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
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

        User::factory()->create([
            'name' => 'Demo Customer',
            'email' => 'customer@example.com',
            'password' => 'password',
            'role' => UserRole::Customer,
        ]);

        $warehouse = Warehouse::factory()->create([
            'code' => 'WH-HCM',
            'name' => 'Ho Chi Minh Warehouse',
        ]);

        Product::factory()
            ->count(3)
            ->sequence(
                ['sku' => 'IPHONE-15', 'name' => 'iPhone 15', 'price' => '19990000.00'],
                ['sku' => 'MACBOOK-AIR-M3', 'name' => 'MacBook Air M3', 'price' => '27990000.00'],
                ['sku' => 'AIRPODS-PRO-2', 'name' => 'AirPods Pro 2', 'price' => '5990000.00'],
            )
            ->create()
            ->each(function (Product $product) use ($warehouse): void {
                Inventory::factory()->create([
                    'warehouse_id' => $warehouse->id,
                    'product_id' => $product->id,
                    'quantity' => 100,
                ]);
            });
    }
}
