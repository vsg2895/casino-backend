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

    /**
     * Below this, the GUIDES section is not linked or listed anywhere.
     *
     * Deliberately not applied to news. A guides section with one evergreen post
     * looks abandoned; a news feed with one post looks new, which is the truth
     * and is fine.
     */
    public const int MIN_TO_PUBLISH_SECTION = 3;

    /** Evergreen, editorially ordered explainers. The original kind. */
    public const string TYPE_GUIDE = 'guide';

    /** Dated posts, newest first. Same columns, different section and feed. */
    public const string TYPE_NEWS = 'news';

    /** @var list<string> */
    public const array TYPES = [self::TYPE_GUIDE, self::TYPE_NEWS];

    protected $fillable = [
        'site_id',
        'type',
        // Where an ingested item came from. All three are null on anything
        // written by hand, which is every article that predates ingestion.
        'source_ref',
        'source_name',
        'source_url',
        'news_category_id',
        'title',
        'slug',
        'excerpt',
        'body',
        'hero_image_path',
        'published_at',
        'position',
        'active',
        'featured',
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
            'active'       => 'boolean',
            'featured'     => 'boolean',
            'read_minutes' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Generated on every creation path, and NEVER regenerated afterwards —
        // the slug is in the public URL, and changing it silently 404s whatever
        // ranked there. Renaming a published article is a redirect, not an edit.
        // Derived from the body on every save. NOT in $fillable — it is
        // calculated, never supplied, and accepting it from a request would let
        // a caller claim any reading time it liked.
        static::saving(function (self $article): void {
            $article->read_minutes = self::readMinutesFor((string) $article->body);
        });

        static::creating(function (self $article): void {
            if (trim((string) $article->slug) === '') {
                $article->slug = Str::slug((string) $article->title);
            }
        });
    }

    /**
     * Minutes to read, at 200 words per minute.
     *
     * Null for an empty body — the card then shows nothing rather than "0 min
     * read", which would be a claim about an article that has no text yet.
     * Tags are stripped first so markup is not counted as words.
     */
    public static function readMinutesFor(string $body): ?int
    {
        $text = trim(html_entity_decode(strip_tags($body)));

        if ($text === '') {
            return null;
        }

        $words = count(preg_split('/\s+/', $text) ?: []);

        return $words === 0 ? null : max(1, (int) round($words / 200));
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** The editorial section this post sits in. Null is a valid, publishable state. */
    public function newsCategory(): BelongsTo
    {
        return $this->belongsTo(NewsCategory::class);
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

    /**
     * Narrow to one kind of article.
     *
     * Every query MUST pass through this. The two kinds share a table, so an
     * unscoped query is not "all articles" in any useful sense — it is the
     * guides feed with news mixed into it, or the reverse.
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    public function isNews(): bool
    {
        return $this->type === self::TYPE_NEWS;
    }

    /**
     * What the public may actually see.
     *
     * TWO conditions, and both have to be here rather than in each controller:
     * the date decides whether it has been published yet, `active` decides
     * whether it is currently shown. A public query that applies one and forgets
     * the other is how a hidden post reappears on one surface and not another.
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->published()->where('active', true);
    }

    /** Ingested from a feed rather than written in the admin. */
    public function scopeFromASource(Builder $query): Builder
    {
        return $query->whereNotNull('source_ref');
    }

    /** True when this item came from a feed. */
    public function isIngested(): bool
    {
        return $this->source_ref !== null;
    }

    /** The editor's picks — what the home page promotes. */
    public function scopeFeatured(Builder $query): Builder
    {
        return $query->where('featured', true);
    }

    /** Editorial order first, then newest — the order the listing renders in. */
    public function scopeInListingOrder(Builder $query): Builder
    {
        return $query->orderBy('position')->orderByDesc('published_at');
    }
}
