<?php

namespace App\Http\Requests;

use App\Enums\ProductStatus;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => [
                'required',
                'integer',
                'distinct:strict',
                Rule::exists('products', 'id')->where(
                    fn (Builder $query): Builder => $query
                        ->whereNull('deleted_at')
                        ->where('status', ProductStatus::Active->value)
                ),
            ],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:4294967295'],
        ];
    }
}
