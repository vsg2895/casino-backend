<?php

declare(strict_types=1);

namespace App\Perfume\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Order extends PerfumeModel
{
    protected $table = 'perfume_orders';

    /** The shop's workflow, in order. The first is what a new order gets. */
    public const array STATUSES = ['new', 'confirmed', 'shipped', 'done', 'cancelled'];

    protected $fillable = [
        'reference', 'customer_name', 'phone', 'email', 'address', 'comment', 'status', 'total', 'items_count',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['total' => 'float', 'items_count' => 'integer'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'order_id');
    }

    /** A short code the shop can read out on the phone; retried on the (vanishingly rare) clash. */
    public static function generateReference(): string
    {
        do {
            $reference = 'BGD-' . Str::upper(Str::random(6));
        } while (self::query()->where('reference', $reference)->exists());

        return $reference;
    }
}
