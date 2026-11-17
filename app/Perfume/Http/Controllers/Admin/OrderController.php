<?php

declare(strict_types=1);

namespace App\Perfume\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Perfume\Http\Requests\OrderStatusRequest;
use App\Perfume\Http\Resources\OrderResource;
use App\Perfume\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OrderController extends Controller
{
    /** Newest first — an order desk reads the list from the top. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $search = trim((string) $request->query('search', ''));

        $orders = Order::query()
            ->with('items')
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', (string) $request->query('status')))
            ->when($search !== '', function (Builder $q) use ($search): void {
                $q->where(function (Builder $w) use ($search): void {
                    $w->where('reference', 'like', "%{$search}%")
                        ->orWhere('customer_name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('id')
            ->paginate(min(100, max(1, (int) $request->query('per_page', 25))));

        return OrderResource::collection($orders);
    }

    public function show(Order $order): OrderResource
    {
        return new OrderResource($order->load('items'));
    }

    public function update(OrderStatusRequest $request, Order $order): OrderResource
    {
        $order->update($request->validated());

        return new OrderResource($order->fresh(['items']));
    }

    public function destroy(Order $order): JsonResponse
    {
        $order->delete();

        return response()->json(null, 204);
    }
}
