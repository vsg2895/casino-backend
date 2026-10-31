<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Author;
use App\Models\Casino;
use App\Models\Category;
use App\Models\Site;
use App\Models\SpecialOffer;
use App\Support\SiteCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;

/**
 * Every indexable detail URL this site publishes, in one response.
 *
 * The sitemap used to assemble itself from the full listing endpoints — every
 * casino with its offers, every offer with its casino, every article with its
 * body excerpt — to read two fields from each. This returns only `slug` and
 * `updated_at`, selected as two columns.
 *
 * THE SAME RULES AS THE PAGES, or the sitemap submits URLs the site refuses:
 *
 *   - casinos:    attached to and active on this site, active globally, not noindex;
 *   - categories: holding at least one such casino, not noindex;
 *   - offers:     active, claimable, casino attached and active, not noindex —
 *                 exactly the public offers listing, plus the noindex flag;
 *   - articles:   visible (published, published_at <= now, active), not noindex,
 *                 and only for a section this site has switched on. Guides also
 *                 need the section to be open (Article::MIN_TO_PUBLISH_SECTION
 *                 visible guides), because /guides and every guide 404 below it;
 *   - authors:    visible authors of this site (their page 404s otherwise).
 *
 * Nothing here is seeded or listed by hand: the response is whatever the
 * database of the environment it runs in holds.
 *
 * Cached like every public read. Any admin change to this content flushes the
 * whole site's cache and revalidates the Next.js `sitemap` tag, so the hour is
 * a ceiling for time-driven changes only (an offer passing its end date, an
 * article reaching its publish time).
 */
class SitemapController extends Controller
{
    public function index(): JsonResponse
    {
        /** @var Site $site */
        $site = app('current_site');

        $data = SiteCache::remember(
            $site->id,
            ['sitemap'],
            'sitemap:site:' . $site->id,
            3600,
            fn (): array => [
                'casinos'    => $this->entries($this->casinos($site), 'casinos'),
                'categories' => $this->entries($this->categories($site), 'categories'),
                'offers'     => $this->entries($this->offers($site), 'special_offers'),
                'articles'   => $this->articles($site),
                'authors'    => $this->entries(Author::query()->forSite($site->id)->visible(), 'authors'),
            ],
        );

        return response()->json(['data' => $data]);
    }

    /** @return Builder<Casino> */
    private function casinos(Site $site): Builder
    {
        return Casino::query()
            ->where('casinos.active', true)
            ->where('casinos.noindex', false)
            ->attachedToSite($site->id);
    }

    /** @return Builder<Category> */
    private function categories(Site $site): Builder
    {
        return Category::query()
            ->where('categories.noindex', false)
            // The categories index applies the same predicate, so a category
            // the menu does not offer is never submitted either.
            ->whereHas('casinos', static function (Builder $casinos) use ($site): void {
                $casinos->where('casinos.active', true)->attachedToSite($site->id);
            });
    }

    /** @return Builder<SpecialOffer> */
    private function offers(Site $site): Builder
    {
        return SpecialOffer::query()
            ->casinoAttachedToSite($site->id)
            ->where('special_offers.active', true)
            ->where('special_offers.noindex', false)
            ->claimable()
            ->whereHas('casino', static fn (Builder $casino): Builder => $casino->where('casinos.active', true));
    }

    /**
     * Guides and news, each only while its section exists on this site.
     *
     * @return list<array{type: string, slug: string, updated_at: string|null}>
     */
    private function articles(Site $site): array
    {
        $types = [];

        if ($site->news_enabled) {
            $types[] = Article::TYPE_NEWS;
        }

        // Counted WITH the noindexed ones: the threshold is about whether the
        // section page exists, and a noindexed guide still appears on it.
        if ($site->guides_enabled && Article::query()
            ->where('site_id', $site->id)
            ->ofType(Article::TYPE_GUIDE)
            ->visible()
            ->count() >= Article::MIN_TO_PUBLISH_SECTION) {
            $types[] = Article::TYPE_GUIDE;
        }

        if ($types === []) {
            return [];
        }

        return Article::query()
            ->where('site_id', $site->id)
            ->whereIn('type', $types)
            ->visible()
            ->where('noindex', false)
            ->orderByDesc('published_at')
            ->get(['type', 'slug', 'updated_at'])
            ->map(static fn (Article $article): array => [
                'type'       => (string) $article->type,
                'slug'       => (string) $article->slug,
                'updated_at' => $article->updated_at?->toISOString(),
            ])
            ->values()
            ->all();
    }

    /**
     * Slug and last-modified, nothing else, ordered so the output is stable.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return list<array{slug: string, updated_at: string|null}>
     */
    private function entries(Builder $query, string $table): array
    {
        return $query
            ->orderBy($table . '.slug')
            ->get([$table . '.slug', $table . '.updated_at'])
            ->map(static fn (Model $row): array => [
                'slug'       => (string) $row->getAttribute('slug'),
                // ISO string, not Carbon: SiteCache would serialize a Carbon
                // into __PHP_Incomplete_Class (see CategoryResource).
                'updated_at' => $row->getAttribute('updated_at')?->toISOString(),
            ])
            ->values()
            ->all();
    }
}
