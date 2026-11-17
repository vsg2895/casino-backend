<?php

declare(strict_types=1);

namespace App\Perfume\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Base for every perfume_* model: the shared `ordered` scope and nothing
 * else. Deliberately not a trait on the platform's models — the store does
 * not touch them.
 */
abstract class PerfumeModel extends Model
{
    /** Admin order, then id for a stable tie-break. */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('position')->orderBy('id');
    }
}
