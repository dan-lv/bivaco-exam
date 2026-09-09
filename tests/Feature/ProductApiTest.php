<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_manage_products(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $createResponse = $this->postJson('/api/products', [
            'sku' => 'IPHONE-15',
            'name' => 'iPhone 15',
            'price' => '19990000.00',
        ]);

        $createResponse
            ->assertCreated()
            ->assertJsonPath('data.sku', 'IPHONE-15')
            ->assertJsonPath('data.status', 'active');

        $productId = $createResponse->json('data.id');

        $this->getJson('/api/products?per_page=10')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.per_page', 10);

        $this->getJson("/api/products/{$productId}")
            ->assertOk()
            ->assertJsonPath('data.name', 'iPhone 15');

        $this->putJson("/api/products/{$productId}", [
            'sku' => 'IPHONE-15',
            'name' => 'iPhone 15 Updated',
            'price' => '18990000.00',
            'status' => 'inactive',
        ])->assertOk()
            ->assertJsonPath('data.name', 'iPhone 15 Updated')
            ->assertJsonPath('data.status', 'inactive');

        $this->deleteJson("/api/products/{$productId}")->assertNoContent();

        $this->assertSoftDeleted('products', ['id' => $productId]);
    }

    public function test_product_payload_is_validated(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/products', [
            'sku' => '',
            'name' => '',
            'price' => '-1.001',
            'status' => 'unknown',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['sku', 'name', 'price', 'status']);
    }

    public function test_customer_cannot_delete_a_product(): void
    {
        $customer = User::factory()->create();
        $product = Product::factory()->create();
        Sanctum::actingAs($customer);

        $this->deleteJson("/api/products/{$product->id}")->assertForbidden();

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'deleted_at' => null,
        ]);
    }
}
