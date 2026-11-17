<?php

declare(strict_types=1);

namespace App\Perfume\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ScentNote extends PerfumeModel
{
    protected $table = 'perfume_scent_notes';

    protected $fillable = [
        'name', 'slug', 'description', 'image_path', 'featured_on_home', 'position',
        'meta_title', 'meta_description',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['featured_on_home' => 'boolean', 'position' => 'integer'];
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'perfume_product_notes', 'scent_note_id', 'product_id')
            ->withPivot(['layer', 'position']);
    }
}
