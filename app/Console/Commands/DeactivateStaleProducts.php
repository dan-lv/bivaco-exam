<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Enums\ProductStatus;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Throwable;

class DeactivateStaleProducts extends Command
{
    protected $signature = 'products:deactivate-stale
                            {--before= : Consider products without a completed order since this YYYY-MM-DD date stale}
                            {--chunk=1000 : Number of product IDs processed per batch}';

    protected $description = 'Deactivate products that have not sold within the configured period';

    public function handle(): int
    {
        $cutoff = $this->cutoff();
        $chunkSize = filter_var(
            $this->option('chunk'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        if ($chunkSize === false) {
            $this->components->error('The --chunk option must be a positive integer.');

            return self::FAILURE;
        }

        if ($cutoff === null) {
            return self::FAILURE;
        }

        $updated = 0;

        $this->staleProducts($cutoff)
            ->select('products.id')
            ->chunkById($chunkSize, function (Collection $products) use ($cutoff, &$updated): void {
                $updated += $this->staleProducts($cutoff)
                    ->whereKey($products->modelKeys())
                    ->update([
                        'status' => ProductStatus::Inactive->value,
                        'updated_at' => now(),
                    ]);
            });

        $this->components->info('Deactivated '.$updated.' stale products.');

        return self::SUCCESS;
    }

    private function staleProducts(CarbonImmutable $cutoff): Builder
    {
        return Product::query()
            ->where('status', ProductStatus::Active->value)
            ->whereDoesntHave(
                'orderItems.order',
                fn (Builder $orders): Builder => $orders
                    ->where('orders.status', OrderStatus::Completed->value)
                    ->where('orders.created_at', '>=', $cutoff)
            );
    }

    private function cutoff(): ?CarbonImmutable
    {
        $before = $this->option('before');

        if ($before === null || $before === '') {
            return CarbonImmutable::now(config('app.timezone'))->subYears(2)->utc();
        }

        try {
            $cutoff = CarbonImmutable::createFromFormat(
                '!Y-m-d',
                (string) $before,
                config('app.timezone')
            );

            if ($cutoff === false) {
                throw new \InvalidArgumentException('Invalid date.');
            }

            return $cutoff->startOfDay()->utc();
        } catch (Throwable) {
            $this->components->error('The --before option must use the YYYY-MM-DD format.');

            return null;
        }
    }
}
