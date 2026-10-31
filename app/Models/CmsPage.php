<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CmsPageFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CmsPage extends Model
{
    /** @use HasFactory<CmsPageFactory> */
    use HasFactory;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';

    protected $fillable = [
        'site_id',
        'slug',
        'title',
        'content',
        'meta_title',
        'meta_description',
        'canonical_url',
        'noindex',
        'status',
        'author_id',
        'reviewed_by_id',
        'last_reviewed_at',
        // A pending revision, invisible to the public until publishDraft().
        'draft_title',
        'draft_content',
        'draft_meta_title',
        'draft_meta_description',
    ];

    /** The live columns a draft replaces when it is published. */
    public const array DRAFT_FIELDS = [
        'draft_title'            => 'title',
        'draft_content'          => 'content',
        'draft_meta_title'       => 'meta_title',
        'draft_meta_description' => 'meta_description',
    ];

    protected function casts(): array
    {
        return [
            'noindex'          => 'boolean',
            'last_reviewed_at' => 'datetime',
            'draft_updated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // `draft_updated_at` is a record of the draft changing, never supplied:
        // it is what tells the admin (and the publish action) a draft exists.
        static::saving(function (self $page): void {
            if (! $page->isDirty(array_keys(self::DRAFT_FIELDS))) {
                return;
            }

            $page->draft_updated_at = $page->hasDraftContent() ? now() : null;
        });
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(Author::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Author::class, 'reviewed_by_id');
    }

    /** Whether any draft column holds something. */
    public function hasDraftContent(): bool
    {
        foreach (array_keys(self::DRAFT_FIELDS) as $field) {
            if (trim((string) $this->getAttribute($field)) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Make the pending draft the live page, and clear it.
     *
     * Only the draft columns that hold something replace their live
     * counterpart: a draft that rewrites the body alone keeps the live title.
     * Also publishes the page — "publish the draft" on a page that was itself
     * a draft would otherwise change nothing anyone can see.
     */
    public function publishDraft(): void
    {
        foreach (self::DRAFT_FIELDS as $draft => $live) {
            if (trim((string) $this->getAttribute($draft)) !== '') {
                $this->setAttribute($live, $this->getAttribute($draft));
            }

            $this->setAttribute($draft, null);
        }

        $this->status = self::STATUS_PUBLISHED;
        $this->save();
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @param Builder<CmsPage> $query */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    /** @param Builder<CmsPage> $query */
    public function scopeForSite(Builder $query, int $siteId): Builder
    {
        return $query->where('site_id', $siteId);
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }
}
