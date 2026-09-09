<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_is_limited_to_one_hundred_requests_per_minute_per_user(): void
    {
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        $this->clearLimiterFor($firstUser);
        $this->clearLimiterFor($secondUser);

        Sanctum::actingAs($firstUser);

        for ($request = 1; $request <= 100; $request++) {
            $response = $this->getJson('/api/products');
            $response->assertOk();
        }

        $this->getJson('/api/products')
            ->assertTooManyRequests()
            ->assertHeader('Retry-After');

        $this->assertNotEmpty(
            Redis::connection()->hgetall($this->limiterKey($firstUser))
        );

        Sanctum::actingAs($secondUser);
        $this->getJson('/api/products')->assertOk();
    }

    private function clearLimiterFor(User $user): void
    {
        Redis::connection()->del($this->limiterKey($user));
    }

    private function limiterKey(User $user): string
    {
        return md5('apiuser:'.$user->getAuthIdentifier());
    }
}
