<?php

declare(strict_types=1);

namespace App\Perfume\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Perfume\Http\Requests\StoreOrderRequest;
use App\Perfume\Services\OrderWriter;
use Illuminate\Http\JsonResponse;

/**
 * Placing an order. There is no payment step: the shop calls the number on
 * the order back and settles the rest by phone, so all this does is record
 * what was asked for and who asked.
 */
class OrderController extends Controller
{
    public function __construct(private readonly OrderWriter $writer) {}

    public function store(StoreOrderRequest $request): JsonResponse
    {
        $order = $this->writer->create($request->validated());

        // Only the reference goes back: enough for the visitor to quote it,
        // and nothing that would let a stranger read someone else's order.
        return response()->json([
            'data' => [
                'reference'   => $order->reference,
                'items_count' => (int) $order->items_count,
                'total'       => $order->total,
            ],
        ], 201);
    }
}
