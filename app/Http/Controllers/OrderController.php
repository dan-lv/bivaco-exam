<?php

namespace App\Http\Controllers;

use App\Actions\CreateOrderAction;
use App\Filters\OrderFilter;
use App\Http\Requests\IndexOrderRequest;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class OrderController extends Controller
{
    public function index(
        IndexOrderRequest $request,
        OrderFilter $filter
    ): AnonymousResourceCollection {
        $user = $request->user();

        $orders = $filter
            ->apply(
                Order::query()
                    ->when(! $user->isAdmin(), fn ($query) => $query->where('user_id', $user->id))
                    ->with(['user', 'warehouse', 'items.product', 'payment']),
                $request->validated()
            )
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 20))
            ->withQueryString();

        return OrderResource::collection($orders);
    }

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
