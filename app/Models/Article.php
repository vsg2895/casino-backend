<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * One editorial guide, belonging to exactly one site.
 *
 * The only content type in this application that is not shared between the six
 * domains — which is precisely why it is the item that earns non-brand traffic.
 */
class Article extends Model
{
    use SoftDeletes;

    /** Below this, the guides section is not linked or listed anywhere. */
    public const int MIN_TO_PUBLISH_SECTION = 3;

    protected $fillable = [
        'site_id',
        'title',
        'slug',
        'excerpt',
        'body',
        'hero_image_path',
        'published_at',
        'position',
        'meta_title',
        'meta_description',
        'canonical_url',
        'noindex',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'position'     => 'integer',
            'noindex'      => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Generated on every creation path, and NEVER regenerated afterwards —
        // the slug is in the public URL, and changing it silently 404s whatever
        // ranked there. Renaming a published article is a redirect, not an edit.
        static::creating(function (self $article): void {
            if (trim((string) $article->slug) === '') {
                $article->slug = Str::slug((string) $article->title);
            }
        });
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * Live articles: published, and not dated in the future.
     *
     * The future check is what makes `published_at` double as scheduling. Without
     * it, setting tomorrow's date would publish immediately and the field would
     * be a lie.
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->whereNotNull('published_at')->where('published_at', '<=', now());
    }

    /** Editorial order first, then newest — the order the listing renders in. */
    public function scopeInListingOrder(Builder $query): Builder
    {
        return $query->orderBy('position')->orderByDesc('published_at');
    }
}
