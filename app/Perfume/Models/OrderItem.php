<?php

declare(strict_types=1);

namespace App\Perfume\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends PerfumeModel
{
    protected $table = 'perfume_order_items';

    protected $fillable = [
        'order_id', 'product_id', 'variant_id', 'brand_name', 'product_name', 'product_slug',
        'variant_label', 'price', 'qty',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['price' => 'float', 'qty' => 'integer'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
