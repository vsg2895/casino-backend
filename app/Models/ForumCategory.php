<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A board within a section.
 *
 * Carries the counters the forum index renders. Nothing reading this model for
 * the index may touch `forum_posts` — that is the performance contract the
 * whole denormalisation exists to keep.
 */
class ForumCategory extends Model
{
    protected $fillable = [
        'site_id',
        'forum_section_id',
        'name',
        'description',
        'icon',
        'position',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'position'       => 'integer',
            'active'         => 'boolean',
            'articles_count' => 'integer',
            'posts_count'    => 'integer',
            'last_post_at'   => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $category): void {
            if (trim((string) $category->slug) === '') {
                $category->slug = Str::slug((string) $category->name);
            }
        });
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(ForumSection::class, 'forum_section_id');
    }

    public function articles(): HasMany
    {
        return $this->hasMany(ForumArticle::class);
    }

    public function lastPost(): BelongsTo
    {
        return $this->belongsTo(ForumPost::class, 'last_post_id');
    }

    public function lastPostUser(): BelongsTo
    {
        return $this->belongsTo(ForumUser::class, 'last_post_user_id');
    }

    public function lastPostArticle(): BelongsTo
    {
        return $this->belongsTo(ForumArticle::class, 'last_post_article_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('position')->orderBy('name');
    }
}
