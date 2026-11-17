<?php

declare(strict_types=1);

namespace App\Perfume\Models;

/**
 * The store's one settings row. `current()` creates it with defaults on
 * first access so the admin and the storefront never meet a missing row.
 */
class Setting extends PerfumeModel
{
    protected $table = 'perfume_settings';

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sale_percent'          => 'integer',
            'delivery_free_from'    => 'float',
            'bonus_percent'         => 'integer',
            'samples_max_per_order' => 'integer',
            'payment_labels'        => 'array',
            'samples_steps'         => 'array',
        ];
    }

    public static function current(): self
    {
        // `fresh()` so the column defaults (site_name, accent_color…) are on
        // the returned model, not only in the database.
        return self::query()->first() ?? self::query()->create([])->fresh();
    }
}
