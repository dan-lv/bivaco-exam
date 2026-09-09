<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

class OrderConflictException extends RuntimeException
{
    public function __construct(string $message, private readonly ?int $productId = null)
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json(array_filter([
            'message' => $this->getMessage(),
            'product_id' => $this->productId,
        ], static fn (mixed $value): bool => $value !== null), 409);
    }
}
