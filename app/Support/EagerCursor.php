<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\LazyCollection;

/**
 * `cursor()` that honours the builder's `with()`.
 *
 * Eloquent's cursor() hydrates one model at a time and SILENTLY DROPS the
 * eager loads, so `Model::with('site')->cursor()` still runs one `sites` query
 * per row. Lazy-loading detection does not catch it either: each model is
 * hydrated alone, and Eloquent only flags lazy loads on models fetched as part
 * of a multi-row result.
 *
 * This keeps cursor()'s single query, order and per-row streaming, and runs the
 * builder's own eager loads (constraints and column lists included) once per
 * chunk, exactly as get() would have run them.
 */
final class EagerCursor
{
    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return LazyCollection<int, TModel>
     */
    public static function of(Builder $query, int $chunkSize = 1000): LazyCollection
    {
        return $query->cursor()
            ->chunk($chunkSize)
            ->flatMap(static fn (LazyCollection $models): array => $query->eagerLoadRelations($models->values()->all()));
    }
}
