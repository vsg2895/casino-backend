<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Models\BonusCategory;
use App\Models\Casino;
use App\Models\CasinoReview;
use App\Models\Category;
use App\Models\CmsPage;
use App\Models\SearchIndexEntry;
use App\Models\SpecialOffer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writes entities into `search_index`, one row per site the entity is visible on.
 *
 * ALL visibility logic lives here and nowhere else. The five sections scope to a
 * site three different ways, and getting any of them wrong leaks content between
 * domains — which is why the read path does no visibility work at all: it trusts
 * that a row's existence already means "publicly visible on this site".
 *
 *   casinos        pivot `casino_site.active` AND casinos.active AND not trashed
 *   special_offers the parent casino's sites, AND the offer's own active/trashed
 *   categories     DERIVED — sites having >=1 visible casino in the category
 *   forum          the review's own site_id, published, AND its casino visible
 *   pages          the page's own site_id, status = published
 *
 * Every sync is a full diff for that ONE entity: rows for sites it is no longer
 * visible on are deleted, the rest upserted. That makes an observer's job a
 * single bounded write rather than a section rebuild — which matters because
 * reviews will outgrow every other table here.
 */
class SearchIndexer
{
    /** Sync one casino, plus everything whose visibility depends on it. */
    public function syncCasino(Casino $casino): void
    {
        $siteIds = $this->casinoSiteIds($casino);

        $this->write(Casino::class, (int) $casino->id, array_map(fn (int $siteId): array => [
            'site_id'   => $siteId,
            'section'   => 'casinos',
            'title'     => (string) $casino->name,
            'subtitle'  => null,
            'body'      => $this->excerpt($casino->description),
            'slug'      => (string) $casino->slug,
            'url'       => '/casinos/' . $casino->slug,
            'image_url' => $casino->image_path,
        ], $siteIds));

        // CASCADE. Hiding a casino must also drop its offers and its reviews —
        // a review is only publicly reachable through a casino that is on the
        // site, so an orphaned review row would be a leak, not a stale row.
        $casino->loadMissing(['specialOffers', 'categories']);

        foreach (SpecialOffer::withTrashed()->where('casino_id', $casino->id)->get() as $offer) {
            $this->syncSpecialOffer($offer);
        }

        foreach (CasinoReview::where('casino_id', $casino->id)->cursor() as $review) {
            $this->syncReview($review);
        }

        // A category's visibility is derived from its casinos, so attaching or
        // hiding a casino can add or remove the whole category from a site.
        foreach ($casino->categories as $category) {
            $this->syncCategory($category);
        }
    }

    public function syncSpecialOffer(SpecialOffer $offer): void
    {
        $casino = $offer->casino()->withTrashed()->first();

        $visible = $casino !== null
            && $offer->active
            && ! $offer->trashed();

        $siteIds = $visible ? $this->casinoSiteIds($casino) : [];

        $this->write(SpecialOffer::class, (int) $offer->id, array_map(fn (int $siteId): array => [
            'site_id'   => $siteId,
            'section'   => 'special_offers',
            'title'     => (string) $offer->title,
            'subtitle'  => $casino?->name,
            /*
             * The BONUS LINE first, then the description.
             *
             * `bonuses` is the headline the card actually shows — "100% up to
             * €2,000", "100 Free Spins for Registration. No Deposit needed" —
             * and it was the one thing about an offer that could not be
             * searched: "deposit needed" and "welcome package" both returned
             * nothing while sitting in plain sight on the cards.
             *
             * Ordered before the description because `excerpt()` truncates at
             * `search.body_limit`, and a long description would otherwise push
             * the line a visitor is most likely to type out of the index
             * entirely.
             */
            'body'      => $this->excerpt(trim(($offer->bonuses ?? '') . ' ' . (string) $offer->description)),
            'slug'      => (string) $offer->slug,
            'url'       => '/special-offers/' . $offer->slug,
            'image_url' => $offer->image_path ?: $casino?->image_path,
        ], $siteIds));

        // CASCADE. A bonus type is visible on a site only while it still has a
        // claimable offer there, so publishing or hiding THIS offer can add or
        // remove the whole heading. Guarded on the column being loaded so an
        // offer with no type costs no extra query.
        if ($offer->bonus_category_id !== null) {
            $bonusCategory = BonusCategory::find($offer->bonus_category_id);

            if ($bonusCategory !== null) {
                $this->syncBonusCategory($bonusCategory);
            }
        }
    }

    /**
     * Sync one bonus category — the bonus TYPE, not the offers under it.
     *
     * Visibility is DERIVED, like Category's, but from a different chain: a
     * bonus type is worth suggesting on a site only where that site actually
     * publishes at least one claimable offer filed under it. The derivation
     * mirrors {@see \App\Http\Controllers\Api\Public\BonusController} exactly,
     * including the `sites.bonus_enabled` gate — that endpoint 404s for a site
     * without the Bonus area, so a suggestion leading there would be a link to
     * a 404.
     *
     * The URL is the offers listing plus the category's anchor. That page
     * renders every bonus section with `id="bonus-<slug>"`, so it is the one
     * address that always exists for a category; the home page carries the same
     * anchors but only for the categories it had room to show.
     */
    public function syncBonusCategory(BonusCategory $category): void
    {
        $this->write(BonusCategory::class, (int) $category->id, array_map(fn (int $siteId): array => [
            'site_id'   => $siteId,
            'section'   => 'bonus_categories',
            'title'     => (string) $category->name,
            'subtitle'  => null,
            'body'      => $this->excerpt($category->description),
            'slug'      => (string) $category->slug,
            'url'       => '/special-offers#bonus-' . $category->slug,
            'image_url' => null,
        ], $this->bonusCategorySiteIds($category)));
    }

    public function syncCategory(Category $category): void
    {
        $this->write(Category::class, (int) $category->id, array_map(fn (int $siteId): array => [
            'site_id'   => $siteId,
            'section'   => 'categories',
            'title'     => (string) $category->name,
            'subtitle'  => null,
            'body'      => null,
            'slug'      => (string) $category->slug,
            'url'       => '/categories/' . $category->slug,
            'image_url' => $category->logo_path,
        ], $this->categorySiteIds($category)));
    }

    /**
     * Sync one review.
     *
     * A review carries no slug and no page of its own — the forum is a single
     * route listing threads grouped by casino — so the URL is that page plus the
     * casino's anchor. The title falls back to a generated label because the
     * headline field is optional and a blank suggestion row is worse than none.
     */
    public function syncReview(CasinoReview $review): void
    {
        $casino = $review->casino()->withTrashed()->first();

        $visible = $casino !== null
            && $review->status === CasinoReview::STATUS_PUBLISHED
            && in_array((int) $review->site_id, $this->casinoSiteIds($casino), true);

        $title = trim((string) $review->title) !== ''
            ? (string) $review->title
            : 'Review of ' . $casino?->name;

        $this->write(CasinoReview::class, (int) $review->id, $visible ? [[
            'site_id'   => (int) $review->site_id,
            'section'   => 'forum',
            'title'     => $title,
            'subtitle'  => $casino?->name,
            // Truncated: unbounded visitor text must not be duplicated wholesale.
            'body'      => $this->excerpt($review->body),
            'slug'      => (string) $casino?->slug,
            'url'       => '/forum#casino-' . $casino?->slug,
            'image_url' => $casino?->image_path,
        ]] : []);
    }

    public function syncPage(CmsPage $page): void
    {
        $visible = $page->status === CmsPage::STATUS_PUBLISHED && $page->site_id !== null;

        $this->write(CmsPage::class, (int) $page->id, $visible ? [[
            'site_id'   => (int) $page->site_id,
            'section'   => 'pages',
            'title'     => (string) $page->title,
            'subtitle'  => null,
            'body'      => $this->excerpt($page->content),
            'slug'      => (string) $page->slug,
            'url'       => '/' . $page->slug,
            'image_url' => null,
        ]] : []);
    }

    /** Drop every row for an entity — used on hard delete. */
    public function remove(Model $model): void
    {
        SearchIndexEntry::query()
            ->where('searchable_type', $model::class)
            ->where('searchable_id', $model->getKey())
            ->delete();
    }

    /**
     * Replace this entity's rows with exactly $rows.
     *
     * Sites the entity has dropped off are deleted; the rest are upserted on the
     * (site_id, searchable_type, searchable_id) unique key, so a double-firing
     * observer updates instead of duplicating.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function write(string $type, int $id, array $rows): void
    {
        $keepSiteIds = array_column($rows, 'site_id');

        // Every site this write could affect: the ones it writes to, plus the
        // ones it may be deleting from.
        $touched = array_unique([
            ...$keepSiteIds,
            ...SearchIndexEntry::query()
                ->where('searchable_type', $type)
                ->where('searchable_id', $id)
                ->pluck('site_id')
                ->all(),
        ]);

        DB::transaction(function () use ($type, $id, $rows, $keepSiteIds): void {
            SearchIndexEntry::query()
                ->where('searchable_type', $type)
                ->where('searchable_id', $id)
                ->when($keepSiteIds !== [], fn ($q) => $q->whereNotIn('site_id', $keepSiteIds))
                ->delete();

            if ($rows === []) {
                return;
            }

            $now = now();
            $weights = config('search.sections');

            SearchIndexEntry::query()->upsert(
                array_map(static fn (array $row): array => [
                    ...$row,
                    'searchable_type' => $type,
                    'searchable_id'   => $id,
                    'weight'          => (int) ($weights[$row['section']]['weight'] ?? 0),
                    'is_active'       => true,
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ], $rows),
                ['site_id', 'searchable_type', 'searchable_id'],
                ['section', 'title', 'subtitle', 'body', 'slug', 'url', 'image_url', 'weight', 'is_active', 'updated_at'],
            );
        });

        $this->bumpVersion($touched);
    }

    /**
     * Invalidate cached suggest responses for the affected sites.
     *
     * The API caches a response per (site, query, section, page) for ~60s, which
     * is right for collapsing keystroke bursts but wrong the moment an admin
     * hides something: a deactivated casino has to leave results NOW, not within
     * a minute. Rather than tracking which of the thousands of possible query
     * keys are stale, the site's version number is part of every key — bumping
     * it retires the whole site's cached set at once.
     *
     * Counter, not a flush: it never touches another site's entries, and it
     * cannot evict unrelated application cache.
     *
     * @param  array<int, int>  $siteIds
     */
    private function bumpVersion(array $siteIds): void
    {
        foreach ($siteIds as $siteId) {
            try {
                $key = self::versionKey((int) $siteId);

                // add() then increment(): increment on a missing key is a no-op
                // on some stores, which would leave the version pinned at 0.
                Cache::add($key, 0);
                Cache::increment($key);
            } catch (\Throwable) {
                // A cache that is down must not fail an admin save. The index
                // itself is already written; the worst case is a stale
                // suggestion for the remainder of the TTL.
            }
        }
    }

    /** Cache key holding a site's search-index version. */
    public static function versionKey(int $siteId): string
    {
        return 'search:version:' . $siteId;
    }

    /** Current version for a site, 0 when never bumped or cache unavailable. */
    public static function version(int $siteId): int
    {
        try {
            return (int) Cache::get(self::versionKey($siteId), 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Sites this casino is publicly visible on.
     *
     * @return list<int>
     */
    private function casinoSiteIds(Casino $casino): array
    {
        if (! $casino->active || $casino->trashed()) {
            return [];
        }

        return DB::table('casino_site')
            ->where('casino_id', $casino->id)
            ->where('active', true)
            ->pluck('site_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Sites this category is publicly visible on.
     *
     * Mirrors the public CategoryController exactly: a category appears only
     * where at least one of its casinos is active on that site. A category with
     * no visible casino is not rendered anywhere, so indexing it would produce a
     * suggestion leading to an empty page.
     *
     * @return list<int>
     */
    /**
     * Sites where this bonus type has at least one claimable, visible offer.
     *
     * One query, and deliberately the same conditions the public Bonus endpoint
     * applies — offer active and unexpired, casino active and attached and
     * active on the site, the site's Bonus area switched on. An inactive bonus
     * category yields no sites at all, which is what clears its rows.
     *
     * @return list<int>
     */
    private function bonusCategorySiteIds(BonusCategory $category): array
    {
        if (! $category->active) {
            return [];
        }

        $today = now()->toDateString();

        return DB::table('special_offers')
            ->join('casinos', 'casinos.id', '=', 'special_offers.casino_id')
            ->join('casino_site', 'casino_site.casino_id', '=', 'casinos.id')
            ->join('sites', 'sites.id', '=', 'casino_site.site_id')
            ->where('special_offers.bonus_category_id', $category->id)
            ->where('special_offers.active', true)
            ->whereNull('special_offers.deleted_at')
            // `claimable()`, inlined: no end date, or one that has not passed.
            ->where(static function ($q) use ($today): void {
                $q->whereNull('special_offers.expires_at')
                    ->orWhereDate('special_offers.expires_at', '>=', $today);
            })
            ->where('casinos.active', true)
            ->whereNull('casinos.deleted_at')
            ->where('casino_site.active', true)
            // The Bonus area is a per-site switch, and its endpoint 404s without
            // it. Suggesting a bonus type on such a site would link to nothing.
            ->where('sites.bonus_enabled', true)
            ->distinct()
            ->pluck('casino_site.site_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    private function categorySiteIds(Category $category): array
    {
        return DB::table('casino_category')
            ->join('casino_site', 'casino_site.casino_id', '=', 'casino_category.casino_id')
            ->join('casinos', 'casinos.id', '=', 'casino_category.casino_id')
            ->where('casino_category.category_id', $category->id)
            ->where('casino_site.active', true)
            ->where('casinos.active', true)
            ->whereNull('casinos.deleted_at')
            ->distinct()
            ->pluck('casino_site.site_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /** Plain-text, length-capped excerpt for the secondary match column. */
    private function excerpt(?string $value): ?string
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $value)) ?? '');

        return $text === '' ? null : Str::limit($text, (int) config('search.body_limit', 2000), '');
    }
}
