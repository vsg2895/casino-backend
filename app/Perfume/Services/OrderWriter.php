<?php

declare(strict_types=1);

namespace App\Perfume\Services;

use App\Perfume\Models\Order;
use App\Perfume\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turns a submitted basket into an order.
 *
 * THE CLIENT'S PRICES ARE NEVER TRUSTED. The browser sends variant ids and
 * quantities only; every name, volume and price is read back from the
 * database here and snapshotted onto the line. Anything else would let a
 * visitor post their own price.
 */
class OrderWriter
{
    /** @param array<string, mixed> $data */
    public function create(array $data): Order
    {
        /** @var array<int, array{variant_id: int, qty: int}> $lines */
        $lines = $data['items'];

        $variants = ProductVariant::query()
            ->with('product.brand')
            ->whereIn('id', array_column($lines, 'variant_id'))
            ->get()
            ->keyBy('id');

        // Every line must still point at a visible product: a basket left open
        // in a tab can outlive the bottle it holds.
        $usable = array_values(array_filter($lines, static function (array $line) use ($variants): bool {
            $variant = $variants->get($line['variant_id']);

            return $variant !== null
                && $variant->product !== null
                && $variant->product->active
                && $variant->product->brand?->active;
        }));

        if ($usable === []) {
            throw ValidationException::withMessages([
                'items' => ['Those fragrances are no longer available. Please refresh your cart.'],
            ]);
        }

        return DB::transaction(function () use ($data, $usable, $variants): Order {
            $order = Order::create([
                'reference'     => Order::generateReference(),
                'customer_name' => $data['customer_name'],
                'phone'         => $data['phone'],
                'email'         => $data['email'] ?? null,
                'address'       => $data['address'] ?? null,
                'comment'       => $data['comment'] ?? null,
                'status'        => 'new',
            ]);

            $total = 0.0;
            $count = 0;
            $priced = false;

            foreach ($usable as $line) {
                $variant = $variants->get($line['variant_id']);
                $product = $variant->product;
                $qty = (int) $line['qty'];

                $order->items()->create([
                    'product_id'    => $product->id,
                    'variant_id'    => $variant->id,
                    'brand_name'    => $product->brand?->name,
                    'product_name'  => $product->name,
                    'product_slug'  => $product->slug,
                    'variant_label' => $variant->label,
                    'price'         => $variant->price,
                    'qty'           => $qty,
                ]);

                $count += $qty;

                if ($variant->price !== null) {
                    $total += $variant->price * $qty;
                    $priced = true;
                }
            }

            // Null, not zero: "nothing was priced" and "it is free" are
            // different things, and the admin list prints them differently.
            $order->update(['items_count' => $count, 'total' => $priced ? round($total, 2) : null]);

            return $order->fresh(['items']);
        });
    }
}
