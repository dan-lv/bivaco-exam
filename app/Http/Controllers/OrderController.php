<?php

namespace App\Http\Controllers;

use App\Actions\CreateOrderAction;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Resources\OrderResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class OrderController extends Controller
{
    public function store(StoreOrderRequest $request, CreateOrderAction $createOrder): JsonResponse
    {
        $validated = $request->validated();
        $order = $createOrder->execute(
            $request->user(),
            $validated['warehouse_id'],
            $validated['items']
        );

        return (new OrderResource($order))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
