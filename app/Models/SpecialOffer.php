<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SpecialOfferFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class SpecialOffer extends Model
{
    /** @use HasFactory<SpecialOfferFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'casino_id',
        'bonus_category_id',
        'title',
        'slug',
        'image_path',
        'banner_image',
        'bonuses',
        'affiliate_url',
        'description',
        'rating',
        'sort_order',
        'active',
        'canonical_url',
        'noindex',
        // Structured bonus terms — see the migration for why the money fields
        // are strings rather than decimals.
        'wagering_requirement',
        'min_deposit',
        'max_cashout',
        'bonus_code',
        'expires_at',
        'terms_url',
    ];

    protected function casts(): array
    {
        return [
            'noindex'    => 'boolean',
            'expires_at' => 'date',
            'rating'     => 'integer',
            'sort_order' => 'integer',
            'active'     => 'boolean',
        ];
    }

    /**
     * Slug lifecycle: the address FOLLOWS THE TITLE, and the old address keeps
     * working.
     *
     * Format is title-based and user-friendly: the title as lowercase words
     * joined by "_", then a final "_" and a short letters-only unique token,
     * e.g. "welcome_bonus_kmxopt".
     *
     * Three cases, in the order they are decided:
     *
     *  1. NO SLUG — on create, and on a duplicate, which replicates without
     *     one. Generated from the title.
     *  2. The admin typed a slug in this save. Theirs wins, untouched: an
     *     address chosen by hand is a decision, not a derivative.
     *  3. The TITLE changed and the slug did not. The slug is rebuilt from the
     *     new title.
     *
     * ── Why case 3 is safe, when it was once forbidden ──────────────────────
     *
     * It used to regenerate on every rename, and that was reverted because
     * nothing wrote a redirect: whatever had been indexed or linked at the old
     * URL simply began 404ing, and the only symptom was traffic that quietly
     * stopped arriving.
     *
     * What changed is that the rename now leaves a trail.
     * {@see \App\Services\OfferSlugRedirectService} writes a 301 from the old
     * path to the new one on every site the offer appears on, re-points any
     * redirect that already led to the old path, and removes one that would
     * shadow the new one. So the address tracks the title — which is what an
     * editor expects after renaming "2nd Deposit Bonus" to "3rd Deposit
     * Bonus" — and every link ever shared still arrives.
     *
     * The service is called by the controller, not from here: a model hook
     * cannot see which sites the offer was on BEFORE the save, and a redirect
     * written for the wrong set of sites is worse than none.
     */
    protected static function booted(): void
    {
        static::saving(function (self $offer): void {
            if (blank($offer->slug)) {
                $offer->slug = static::generateUniqueSlug(
                    (string) $offer->title,
                    $offer->getKey(),
                    $offer->casinoSlugForSlug(),
                );

                return;
            }

            // An explicit slug in this same save is the admin's choice and is
            // never overwritten by the title.
            if ($offer->exists && $offer->isDirty('title') && ! $offer->isDirty('slug')) {
                $offer->slug = static::generateUniqueSlug(
                    (string) $offer->title,
                    $offer->getKey(),
                    $offer->casinoSlugForSlug(),
                );
            }
        });
    }

    /**
     * The fewest words of prose that make an offer page worth indexing.
     *
     * Not a number pulled from the air: the median description across the live
     * offers was 33 words, and Search Console reported six of them as "Crawled
     * — currently not indexed". Google was right. A page whose body is a title,
     * a button and two sentences has nothing to rank, and submitting thirty of
     * them spends crawl budget that the eleven finished ones need.
     */
    public const int MIN_INDEXABLE_WORDS = 80;

    /**
     * Whether this offer's page is worth putting in front of Google.
     *
     * THE POINT of this living on the model rather than in a page template: the
     * robots tag, the sitemap and anything server-side that needs the same
     * answer all read one definition. Two implementations of "is this thin"
     * would drift, and the first symptom would be a sitemap promising a page
     * that serves noindex — the contradiction Search Console reports.
     *
     * Three ways to qualify, in order:
     *
     *   1. An explicit admin `noindex` always wins. Someone decided; nothing
     *      computed overrules a decision.
     *   2. Real prose — MIN_INDEXABLE_WORDS or more in `description`.
     *   3. Filled bonus terms. An offer with the wagering requirement, the
     *      minimum deposit or the withdrawal cap written out is genuinely
     *      useful even with a short description: those numbers are the thing a
     *      player came to find, and they are unique to this offer.
     *
     * `bonus_code` deliberately does NOT qualify. A coupon string says nothing
     * about what the offer is worth, and letting it count would mark a one-word
     * page indexable.
     *
     * Note what this is NOT: it is not `active`. An inactive offer is hidden
     * for a different reason and its page serves the stricter
     * `index: false, follow: false` — that gate stays where it is.
     */
    public function isIndexable(): bool
    {
        if ($this->noindex) {
            return false;
        }

        $prose = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string) $this->description))) ?? '');

        if (str_word_count($prose) >= self::MIN_INDEXABLE_WORDS) {
            return true;
        }

        return filled($this->wagering_requirement)
            || filled($this->min_deposit)
            || filled($this->max_cashout);
    }

    /**
     * This offer's casino slug, for the URL prefix — or null when it cannot be
     * resolved.
     *
     * Reads the loaded relation when there is one and falls back to a single
     * keyed lookup, because this runs inside `saving()` where the relation is
     * usually not loaded (an admin POST sets `casino_id` and nothing else).
     * Null rather than a guess: a prefix we cannot establish is better omitted
     * than invented, and generateUniqueSlug() handles null by using the title
     * alone.
     */
    public function casinoSlugForSlug(): ?string
    {
        $casino = $this->relationLoaded('casino')
            ? $this->getRelation('casino')
            : ($this->casino_id !== null ? Casino::query()->find($this->casino_id, ['id', 'slug']) : null);

        $slug = trim((string) ($casino->slug ?? ''));

        return $slug === '' ? null : $slug;
    }

    /**
     * Build a readable, unique slug: {casino-slug}-{offer-title-slug}.
     *
     * WHAT THIS REPLACED, and why it mattered. The old shape was
     * `{title_with_underscores}_{6 random letters}` — and the random token was
     * appended ALWAYS, not only on collision. Combined with the admin's
     * duplicate action (which suffixes the title with " (Copy)"), it minted
     * URLs like `2nd_deposit_bonus_copy_sagmuq`: underscores Google reads as
     * one word, a meaningless tail, and the word "copy" published to visitors.
     *
     * Three rules now:
     *
     *   1. The CASINO's slug leads, so the URL says whose offer it is and two
     *      operators running a "Welcome Package" do not collide by default.
     *   2. Hyphens throughout — Google treats a hyphen as a word separator and
     *      an underscore as a joiner, so `2nd_deposit_bonus` was one token.
     *   3. A numeric suffix ONLY on a real collision: `-2`, `-3`. A unique slug
     *      stays clean, which is the whole point.
     *
     * `$ignoreId` excludes the row being saved, so re-saving an offer without
     * touching its title does not bump it to `-2`. Trashed rows are included in
     * the check because the slug column is unique across them too.
     */
    public static function generateUniqueSlug(
        string $title,
        int|string|null $ignoreId = null,
        ?string $casinoSlug = null,
    ): string {
        $base = static::slugBase($title, $casinoSlug);

        $slug = $base;
        $suffix = 1;

        while (
            static::withTrashed()
                ->where('slug', $slug)
                ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
                ->exists()
        ) {
            $suffix++;
            $slug = $base . '-' . $suffix;
        }

        return $slug;
    }

    /**
     * Title (optionally prefixed by the casino) → a hyphenated, a-z0-9 slug.
     *
     * Public because the admin's update request normalises a hand-typed slug
     * through it: a slug an editor writes and a slug this class generates then
     * have the same shape, decided in one place.
     *
     * The substitutions are the ones that would otherwise be destroyed by
     * `ascii()` and lose the meaning a reader needs in a URL:
     *
     *   "25% Cashback"      → cashy-25-percent-cashback
     *   "Slots & Live"      → bitstarz-slots-and-live
     *
     * " (Copy)" is stripped rather than transliterated. The admin's duplicate
     * action puts it in the TITLE, which is right — an editor needs to see
     * which row is the copy — but it has no business in a public URL, and
     * leaving it there is how `..._copy_sagmuq` happened. The duplicate simply
     * collides with its original and takes `-2` from generateUniqueSlug().
     */
    public static function slugBase(string $title, ?string $casinoSlug = null): string
    {
        $slug = Str::of($title)
            ->lower()
            // Before ascii(), which would drop both characters silently.
            ->replace('%', ' percent ')
            ->replace('&', ' and ')
            // The duplicate marker, in the shapes the admin and editors produce.
            ->replaceMatches('/\s*\(\s*cop(?:y|ies)\s*\d*\s*\)\s*$/i', '')
            ->ascii()
            ->replaceMatches('/[^a-z0-9]+/', '-')
            ->trim('-')
            ->value();

        if ($slug === '') {
            $slug = 'offer';
        }

        $prefix = trim((string) $casinoSlug, '-');

        /*
         * Not prefixed when the title ALREADY NAMES the casino, wherever it
         * does so. Checking only the start was not enough: titles in the wild
         * put the operator at either end — "BitStarz Welcome Package" and
         * "Sample Offer 1 — RocketPlay" — and prefixing the second produced
         * `rocketplay-sample-offer-1-rocketplay`.
         *
         * The hyphen padding makes it a WHOLE-TOKEN test, so the prefix `bit`
         * is not considered present in `bitstarz-bonus`. It cannot catch a
         * spelling that differs from the slug ("Bit Starz" against `bitstarz`),
         * which is the right limit: that is a title to fix, not a string to
         * guess at.
         */
        if ($prefix === '' || $slug === $prefix || str_contains('-' . $slug . '-', '-' . $prefix . '-')) {
            return $slug;
        }

        return $prefix . '-' . $slug;
    }

    public function bonusCategory(): BelongsTo
    {
        return $this->belongsTo(BonusCategory::class);
    }

    public function casino(): BelongsTo
    {
        return $this->belongsTo(Casino::class);
    }

    /**
     * Offers that may still be presented as claimable.
     *
     * An offer past its expiry date is NOT one, and this is a compliance rule
     * rather than a tidiness one: showing a claim button for a bonus that no
     * longer exists sends a player to an operator expecting terms they will not
     * get. `whereNull` is load-bearing — an offer with no expiry never expires,
     * and MySQL drops NULLs from a plain `>=` comparison.
     */
    public function scopeClaimable(Builder $query): Builder
    {
        return $query->where(static function (Builder $q): void {
            $q->whereNull('expires_at')->orWhereDate('expires_at', '>=', now()->toDateString());
        });
    }

    /** True once the offer's stated end date has passed. */
    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isBefore(now()->startOfDay());
    }
}
