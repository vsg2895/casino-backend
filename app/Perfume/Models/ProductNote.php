<?php

declare(strict_types=1);

namespace App\Perfume\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductNote extends PerfumeModel
{
    protected $table = 'perfume_product_notes';

    protected $fillable = ['product_id', 'scent_note_id', 'layer', 'position'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function scentNote(): BelongsTo
    {
        return $this->belongsTo(ScentNote::class, 'scent_note_id');
    }
}
