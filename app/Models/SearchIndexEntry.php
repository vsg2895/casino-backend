<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One searchable row: a single entity as it appears on a single site.
 *
 * A CACHE of the entity tables, never a source of truth — every row is
 * reproducible by `search:reindex`. Nothing writes here except
 * {@see \App\Services\Search\SearchIndexer} and that command.
 *
 * NEVER store PII. Reviews contribute their author's DISPLAY NAME at most, and
 * only because it is already published beside the review; `author_email` must
 * not reach this table, and there is no IP anywhere in the schema to leak.
 */
class SearchIndexEntry extends Model
{
    protected $table = 'search_index';

    protected $fillable = [
        'site_id',
        'searchable_type',
        'searchable_id',
        'section',
        'title',
        'subtitle',
        'body',
        'slug',
        'url',
        'image_url',
        'weight',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'searchable_id' => 'integer',
            'weight'        => 'integer',
            'is_active'     => 'boolean',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function searchable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The only rows the public may see.
     *
     * @param  Builder<SearchIndexEntry>  $query
     * @return Builder<SearchIndexEntry>
     */
    public function scopeVisible(Builder $query, int $siteId): Builder
    {
        return $query->where('site_id', $siteId)->where('is_active', true);
    }
}
