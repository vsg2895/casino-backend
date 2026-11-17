<?php

declare(strict_types=1);

namespace App\Perfume\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductVariant extends PerfumeModel
{
    protected $table = 'perfume_product_variants';

    protected $fillable = ['product_id', 'volume_ml', 'label', 'price', 'old_price', 'stock', 'is_sample', 'position'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'volume_ml' => 'integer',
            // Dollars and cents. Float, not Laravel's `decimal` cast: that
            // returns a STRING, which would reach the admin and the
            // storefront as "129.99" where both expect a number.
            'price'     => 'float',
            'old_price' => 'float',
            'stock'     => 'integer',
            'is_sample' => 'boolean',
            'position'  => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
