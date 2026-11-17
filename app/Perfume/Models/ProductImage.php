<?php

declare(strict_types=1);

namespace App\Perfume\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductImage extends PerfumeModel
{
    protected $table = 'perfume_product_images';

    protected $fillable = ['product_id', 'path', 'alt', 'position'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
