<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Support\SchemaGuard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
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

    /**
     * The columns a reader sees. Only a change to one of these moves
     * `content_updated_at` — see stampContentUpdate().
     *
     * @var list<string>
     */
    public const array CONTENT_FIELDS = ['title', 'excerpt', 'body', 'editor_note', 'hero_image_path'];

    /*
     * Where a scraped news post stands with `news:rewrite` — the admin's filter
     * and the line under each title. Derived, never stored: "rewritten" is
     * still `body IS NOT NULL`, the rule the rewrite queue itself runs on, and
     * the attempt columns only say what happened on the way there.
     */

    /** Original copy has been written. */
    public const string REWRITE_REWRITTEN = 'rewritten';

    /** No copy yet, and news:rewrite has not tried this post. */
    public const string REWRITE_NOT_ATTEMPTED = 'not_attempted';

    /** No copy yet, although news:rewrite tried — see rewrite_result / rewrite_error. */
    public const string REWRITE_ATTEMPT_FAILED = 'attempt_failed';

    /** Written in the admin, not scraped: nothing to rewrite. */
    public const string REWRITE_NOT_APPLICABLE = 'written_here';

    /** Filter value for "not rewritten, whether or not it was tried". */
    public const string REWRITE_FILTER_NOT_REWRITTEN = 'not_rewritten';

    /** @var list<string> The values the admin listing's `rewrite` filter accepts. */
    public const array REWRITE_FILTERS = [
        self::REWRITE_REWRITTEN,
        self::REWRITE_FILTER_NOT_REWRITTEN,
        self::REWRITE_NOT_ATTEMPTED,
        self::REWRITE_ATTEMPT_FAILED,
    ];

    protected $fillable = [
        'site_id',
        'type',
        // Where an ingested item came from. All three are null on anything
        // written by hand, which is every article that predates ingestion.
        'source_ref',
        'source_name',
        'source_url',
        'title',
        'slug',
        'excerpt',
        'body',
        // Plain text, written by an editor, shown as "Editor's note". See the
        // 2026_11_28 migration.
        'editor_note',
        'hero_image_path',
        'published_at',
        'active',
        'featured',
        'to_be_most_popular',
        'meta_title',
        'meta_description',
        'canonical_url',
        'noindex',
        // Byline — authors of THIS article's site only; see the Form Requests.
        'author_id',
        'reviewed_by_id',
        'last_reviewed_at',
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
            'last_reviewed_at' => 'datetime',
            'content_updated_at' => 'datetime',
            'rewrite_attempts'     => 'integer',
            'rewrite_attempted_at' => 'datetime',
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

                // A generated news slug steps past a deleted post's URL, which
                // answers 410 Gone (DeletedNewsSlug). A slug typed by an editor
                // is checked by the Form Request instead.
                if ($article->isNews()) {
                    $base = (string) $article->slug;
                    $n = 2;

                    while (DeletedNewsSlug::holds((int) $article->site_id, (string) $article->slug)) {
                        $article->slug = $base . '-' . $n++;
                    }
                }
            }
        });

        // The moment a news post goes live is its publish date — see
        // stampPublishDateOnGoingLive().
        static::saving(function (self $article): void {
            $article->stampPublishDateOnGoingLive();
            $article->stampMostPopularPick();
            $article->stampContentUpdate();
        });

        // Deleting an article deletes its cover file — see deleteCoverFile().
        // `deleted` fires for a soft delete as well as a force delete, and for
        // every path that deletes a MODEL: the admin's Delete button and
        // news:scrape's daily pruning alike.
        static::deleted(function (self $article): void {
            $article->deleteCoverFile();
        });
    }

    /**
     * Remove this article's cover from the public disk.
     *
     * Only files this application wrote: a drawn cover (`news/…`) or an admin
     * upload (`uploads/…`). An absolute URL points at somebody else's server
     * and is left alone.
     *
     * Kept when another article still uses the same path — an editor can paste
     * one upload's path into a second article, and deleting the first must not
     * break the second. Soft-deleted rows do not count: their cover is gone
     * with them.
     */
    public function deleteCoverFile(): void
    {
        $path = ltrim(trim((string) $this->hero_image_path), '/');

        if ($path === '' || ! (str_starts_with($path, 'news/') || str_starts_with($path, 'uploads/'))) {
            return;
        }

        $sharedElsewhere = self::query()
            ->whereKeyNot($this->getKey())
            ->where('hero_image_path', $this->hero_image_path)
            ->exists();

        if (! $sharedElsewhere) {
            Storage::disk('public')->delete($path);
        }
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
     * `content_updated_at` follows the text, not the row.
     *
     * Set on creation and whenever a CONTENT_FIELDS column changes. The admin
     * toggles (featured, most popular, shown) re-send every field unchanged,
     * so they are not dirty and leave it alone. NOT in $fillable: it is
     * derived, never supplied. Skipped until its migration has run.
     */
    protected function stampContentUpdate(): void
    {
        if (! SchemaGuard::hasColumn('articles', 'content_updated_at')) {
            return;
        }

        if (! $this->exists || $this->isDirty(self::CONTENT_FIELDS)) {
            $this->content_updated_at = now();
        }
    }

    /**
     * The public "last modified" date: `dateModified` and sitemap `lastmod`.
     *
     * The later of the text's last change and the publish date. A news post is
     * written before it is approved, and approval restamps `published_at`, so
     * the text alone would read as older than the publication. Falls back to
     * `updated_at` until the column's migration has run (and on rows selected
     * without it).
     */
    public function contentModifiedAt(): ?Carbon
    {
        $content = $this->content_updated_at ?? $this->updated_at;
        $published = $this->published_at;

        if ($content === null || ($published !== null && $published->greaterThan($content))) {
            return $published;
        }

        return $content;
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

    /** Who wrote it. Null means no named author — the page shows no byline. */
    public function author(): BelongsTo
    {
        return $this->belongsTo(Author::class);
    }

    /** Who checked it. Independent of the author; either may be set alone. */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Author::class, 'reviewed_by_id');
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
     * THREE conditions, and all of them have to be here rather than in each
     * controller: the date decides whether it has been published yet, `active`
     * decides whether it is currently shown, and a body decides whether there is
     * an article at all. A public query that applies one and forgets another is
     * how a hidden post reappears on one surface and not another.
     *
     * The body is what keeps a scraped item private until it is rewritten. A
     * feed item is stored with `body = null` and the SOURCE's headline and
     * teaser as its title and excerpt (NewsScrapeService); approving it before
     * news:rewrite ran used to publish that wording at a live URL — noindex,
     * but readable and linkable. Now it is a 404, out of every listing and out
     * of the sitemap, until there is a body of this site's own.
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->published()
            ->where('active', true)
            ->whereNotNull('body')
            ->where('body', '!=', '');
    }

    /** Ingested from a feed rather than written in the admin. */
    public function scopeFromASource(Builder $query): Builder
    {
        return $query->whereNotNull('source_ref');
    }

    /**
     * Scraped posts in one rewrite state — the admin listing's `rewrite` filter.
     * Every value narrows to ingested posts: a post written in the admin was
     * never anything to rewrite. Before the 2026_12_04 migration there are no
     * attempt columns, so "never tried" is every waiting draft and "tried" none.
     */
    public function scopeRewriteState(Builder $query, string $state): Builder
    {
        $tracked = SchemaGuard::hasColumn('articles', 'rewrite_attempts');

        $query->whereNotNull('source_ref');

        return match ($state) {
            self::REWRITE_REWRITTEN            => $query->whereNotNull('body'),
            self::REWRITE_FILTER_NOT_REWRITTEN => $query->whereNull('body'),
            self::REWRITE_NOT_ATTEMPTED        => $query->whereNull('body')->when($tracked, fn (Builder $q) => $q->where('rewrite_attempts', 0)),
            self::REWRITE_ATTEMPT_FAILED       => $tracked
                ? $query->whereNull('body')->where('rewrite_attempts', '>', 0)
                : $query->whereRaw('1 = 0'),
            default                            => $query,
        };
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
