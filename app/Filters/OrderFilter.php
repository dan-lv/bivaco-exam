<?php

namespace App\Filters;

use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class OrderFilter
{
    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Order>
     */
    public function apply(Builder $query, array $filters): Builder
    {
        $query->when(
            $filters['status'] ?? null,
            fn (Builder $query, string $status): Builder => $query->where('status', $status)
        );

        $keyword = trim((string) ($filters['keyword'] ?? ''));

        if ($keyword !== '') {
            $pattern = '%'.addcslashes($keyword, '\\%_').'%';

            $query->where(function (Builder $query) use ($pattern): void {
                $query
                    ->where('order_number', 'like', $pattern)
                    ->orWhereHas('items.product', function (Builder $query) use ($pattern): void {
                        $query->where(function (Builder $query) use ($pattern): void {
                            $query
                                ->where('sku', 'like', $pattern)
                                ->orWhere('name', 'like', $pattern);
                        });
                    });
            });
        }

        if (isset($filters['from_date'])) {
            $query->where(
                'created_at',
                '>=',
                $this->dateBoundary($filters['from_date'])->startOfDay()
            );
        }

        if (isset($filters['to_date'])) {
            $query->where(
                'created_at',
                '<=',
                $this->dateBoundary($filters['to_date'])->endOfDay()
            );
        }

        return $query;
    }

    private function dateBoundary(string $date): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat(
            '!Y-m-d',
            $date,
            config('app.timezone')
        )->utc();
    }
}
