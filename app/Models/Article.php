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
        'active',
        'featured',
        'to_be_most_popular',
        'meta_title',
        'meta_description',
        'canonical_url',
        'noindex',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'noindex'      => 'boolean',
            'active'       => 'boolean',
            'featured'     => 'boolean',
            'to_be_most_popular' => 'boolean',
            'most_popular_at'    => 'datetime',
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

        // The moment a news post goes live is its publish date — see
        // stampPublishDateOnGoingLive().
        static::saving(function (self $article): void {
            $article->stampPublishDateOnGoingLive();
            $article->stampMostPopularPick();
        });
    }

    /**
     * `most_popular_at` follows the flag it describes.
     *
     * Set when a post is picked for the rail, cleared when it is dropped from
     * it — so the column can never claim a pick date for something that is not
     * picked, and a post put back in the rail goes to the top where the editor
     * who just promoted it expects to find it.
     *
     * Not fillable, and never taken from a request: it is a record of an action,
     * not a field anyone fills in.
     */
    protected function stampMostPopularPick(): void
    {
        if (! $this->isDirty('to_be_most_popular')) {
            return;
        }

        $this->most_popular_at = $this->to_be_most_popular ? now() : null;
    }

    /**
     * A NEWS post's publish date is when THIS site published it.
     *
     * Ingested rows carry the SOURCE's date, which is right while they sit as
     * drafts — the feed's chronology has to be truthful the moment an editor
     * approves one. It stops being right the moment it is approved: a post
     * collected twelve days ago and turned on today was published today, and
     * the card saying "12 days ago" is describing someone else's publication,
     * not ours. So going live restamps it.
     *
     * Three things it will NOT overwrite, in the order they are checked:
     *
     *  - a GUIDE's date. Guides are evergreen and editorially ordered; their
     *    date is a fact about the writing, not a release.
     *  - a date the editor set IN THE SAME SAVE. Typing a date and switching
     *    the post on is an explicit statement about when it was published, and
     *    believing the person beats believing the clock.
     *  - a FUTURE date. That is the scheduling feature ({@see scopePublished});
     *    stamping now() over it would publish the post immediately and make the
     *    field a lie.
     *
     * Only fires on the transition, so re-saving a live post leaves its date
     * alone. Switching a post off and on again DOES re-date it — on this feed
     * that is the honest reading of "published", but it is the one surprise
     * here worth knowing about.
     */
    protected function stampPublishDateOnGoingLive(): void
    {
        if (! $this->isNews() || ! $this->active) {
            return;
        }

        if (! $this->isDirty('active') || $this->isDirty('published_at')) {
            return;
        }

        if ($this->published_at?->isFuture()) {
            return;
        }

        $this->published_at = now();
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

    /** Picked for the news page's "Most Popular" rail. */
    public function scopeToBeMostPopular(Builder $query): Builder
    {
        return $query->where('to_be_most_popular', true);
    }

    /**
     * The "Most Popular" rail's order: most recently PICKED first.
     *
     * The rail is a curated list, so what ranks it is when the editor put each
     * post there — `published_at` is about the story, not about the pick. It
     * stays as the tiebreak for rows picked in the same second (and for any
     * pick made before {@see \App\Models\Article::stampMostPopularPick()}
     * existed), with `id` last, because an import stamps many rows with one
     * timestamp.
     *
     * MySQL sorts NULL last under DESC, which is the behaviour wanted here: a
     * pick with no date recorded sits below every dated one rather than on top.
     */
    public function scopeMostPopularFirst(Builder $query): Builder
    {
        return $query
            ->orderByDesc('most_popular_at')
            ->orderByDesc('published_at')
            ->orderByDesc('id');
    }

    /**
     * Newest first, and nothing else — the order BOTH sections render in.
     *
     * There used to be a `position` column ahead of the date, and an ordering
     * that differed per section. News dropped it first: a feed's order IS its
     * chronology, and a hand-set position pinned an older story above a newer
     * one with nothing on the page to explain it. Guides followed, because in
     * practice nothing but a seeder ever set the value — it was a control
     * nobody used that still had to be explained, validated and reasoned about
     * everywhere. An editor who wants a guide higher up moves its date.
     *
     * `id` breaks ties, because an import stamps many rows with one timestamp.
     */
    public function scopeInListingOrder(Builder $query): Builder
    {
        return $query->orderByDesc('published_at')->orderByDesc('id');
    }
}
