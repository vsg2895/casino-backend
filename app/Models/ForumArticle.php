<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A discussion topic. Admin-authored; members reply, never create.
 */
class ForumArticle extends Model
{
    use SoftDeletes;

    public const string STATUS_DRAFT = 'draft';

    public const string STATUS_PUBLISHED = 'published';

    public const string STATUS_ARCHIVED = 'archived';

    /**
     * Hot Threads weighting, and the window it looks at.
     *
     * A reply is worth far more than a view because it is far harder to forge:
     * views are one request, replies survive moderation. Both are public
     * constants so `forum:rescore` and any test read the same numbers.
     */
    public const int HOT_VIEW_WEIGHT = 1;

    public const int HOT_REPLY_WEIGHT = 25;

    public const int HOT_WINDOW_DAYS = 7;

    protected $fillable = [
        'site_id',
        'forum_category_id',
        'user_id',
        'title',
        'slug',
        'excerpt',
        'body',
        'cover_image_path',
        'status',
        'pinned',
        'locked',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'pinned'       => 'boolean',
            'locked'       => 'boolean',
            'published_at' => 'datetime',
            'last_post_at' => 'datetime',
            'posts_count'  => 'integer',
            'views_count'  => 'integer',
            'hot_score'    => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $article): void {
            if (trim((string) $article->slug) === '') {
                $article->slug = Str::slug((string) $article->title);
            }

            // Publishing without a date would leave the article out of every
            // "newest first" listing and out of the sitemap.
            if ($article->status === self::STATUS_PUBLISHED && $article->published_at === null) {
                $article->published_at = now();
            }
        });
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ForumCategory::class, 'forum_category_id');
    }

    /** The ADMIN who wrote it — see the migration. */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function posts(): HasMany
    {
        return $this->hasMany(ForumPost::class);
    }

    public function lastPost(): BelongsTo
    {
        return $this->belongsTo(ForumPost::class, 'last_post_id');
    }

    public function lastPostUser(): BelongsTo
    {
        return $this->belongsTo(ForumUser::class, 'last_post_user_id');
    }

    /**
     * Publicly visible: published, and dated.
     *
     * The `published_at` bound is what makes a future-dated article a scheduled
     * one rather than an immediately live one.
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    /** Whether a member may reply right now. */
    public function acceptsPosts(): bool
    {
        return ! $this->locked && $this->status === self::STATUS_PUBLISHED;
    }
}
