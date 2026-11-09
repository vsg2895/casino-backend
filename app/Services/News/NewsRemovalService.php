<?php

declare(strict_types=1);

namespace App\Services\News;

use App\Jobs\RevalidateNextJsSites;
use App\Models\Article;
use App\Models\DeletedNewsSlug;
use App\Models\Site;
use App\Support\SiteCache;
use Illuminate\Support\Facades\DB;

/**
 * Deleting news posts without leaving SEO damage behind.
 *
 * Every path that deletes a news post goes through here — the admin's Delete
 * button, `news:delete-old` and news:scrape's (optional) daily pruning — so
 * each one leaves the site in the same state:
 *
 *  - the row is deleted FOR GOOD (force delete). What SEO and the scraper
 *    still need is kept in a tombstone (DeletedNewsSlug): the slug, so the
 *    old URL answers 410 Gone — which search engines drop faster than a 404,
 *    see NewsController::gone() — and is never handed to another post; and
 *    the feed id, so news:scrape does not take the same item back in;
 *  - its cover file goes with it (Article::deleted);
 *  - every link to it in this site's OTHER articles is unwrapped to its plain
 *    text, so no page keeps pointing crawlers and readers at a dead URL. Only
 *    the anchor goes; the words stay. Done without model events, so the
 *    linking article's content date (`content_updated_at`) and its `noindex`
 *    do not move — and a news link never counted toward NewsIndexGate, so no
 *    other post can lose its indexability over it;
 *  - the site's API cache is flushed and the Next.js tags revalidated, which
 *    takes the post out of the feed, the rails, the author pages, sitemap.xml
 *    and news-sitemap.xml at once.
 */
final class NewsRemovalService
{
    /**
     * Delete these posts of one site. Returns the slugs it deleted.
     *
     * `$refresh = false` leaves the cache and the revalidation to a caller that
     * does its own (news:scrape refreshes once for everything a run touched).
     *
     * @param  iterable<Article>  $posts
     * @return list<string>
     */
    public function remove(Site $site, iterable $posts, bool $refresh = true): array
    {
        $slugs = [];

        foreach ($posts as $post) {
            // Never another site's post or a guide, whatever the caller passed.
            if ($post->site_id !== $site->id || ! $post->isNews()) {
                continue;
            }

            // Tombstone first, then the row — as a MODEL, never a bulk query,
            // so Article::deleted removes the cover.
            DB::transaction(static function () use ($post): void {
                DeletedNewsSlug::record($post);
                $post->forceDelete();
            });
            $slugs[] = (string) $post->slug;
        }

        if ($slugs === []) {
            return [];
        }

        $this->unlink($site, $slugs);

        if ($refresh) {
            $this->refresh($site, $slugs);
        }

        return $slugs;
    }

    /**
     * Hard-delete the news posts this site soft-deleted before deletes were
     * permanent (the admin's old Delete, the old daily pruning). Same path as
     * remove(), so each one leaves a tombstone keeping its original delete
     * date, and any link still pointing at it is unwrapped.
     *
     * @return list<string>
     */
    public function purgeTrashed(Site $site, bool $refresh = true): array
    {
        $slugs = [];

        Article::onlyTrashed()
            ->where('site_id', $site->id)
            ->ofType(Article::TYPE_NEWS)
            ->chunkById(200, function ($posts) use ($site, &$slugs): void {
                $slugs = [...$slugs, ...$this->remove($site, $posts, refresh: false)];
            });

        if ($slugs !== [] && $refresh) {
            $this->refresh($site, $slugs);
        }

        return $slugs;
    }

    /**
     * Unwrap every link to `/news/<slug>` in this site's live articles, guides
     * and news alike. Returns how many articles changed.
     *
     * @param  list<string>  $slugs
     */
    public function unlink(Site $site, array $slugs): int
    {
        $paths = [];

        foreach ($slugs as $slug) {
            $paths['/news/' . mb_strtolower($slug)] = true;
        }

        $domain = self::bareHost((string) $site->domain);
        $changed = 0;

        Article::query()
            ->where('site_id', $site->id)
            ->where('body', 'like', '%/news/%')
            ->select(['id', 'site_id', 'body'])
            ->chunkById(200, function ($articles) use ($paths, $domain, &$changed): void {
                foreach ($articles as $article) {
                    $body = (string) $article->body;
                    $unlinked = self::unlinkPaths($body, $paths, $domain);

                    if ($unlinked !== $body) {
                        // Quietly: the anchor is gone, the words are not, so
                        // this is not a content change (no content_updated_at,
                        // no read_minutes recount, nothing else re-derived).
                        $article->forceFill(['body' => $unlinked])->saveQuietly();
                        $changed++;
                    }
                }
            });

        return $changed;
    }

    /** @param  list<string>  $slugs */
    private function refresh(Site $site, array $slugs): void
    {
        SiteCache::flushSite($site->id);

        // RevalidationService adds `sitemap` and `site:<slug>` to every call,
        // so the sitemaps and the pages whose links were unwrapped refresh too.
        RevalidateNextJsSites::dispatch(
            ['news', ...array_map(static fn (string $slug): string => 'news:' . $slug, array_values(array_unique($slugs)))],
            [$site->id],
        );
    }

    /**
     * `$body` with each `<a>` whose href is one of `$paths` replaced by its
     * inner HTML. A relative href, or an absolute one on this site's domain
     * (with or without `www.`); trailing slash, query and fragment ignored.
     *
     * @param  array<string, true>  $paths  lower-cased `/news/<slug>` keys
     */
    public static function unlinkPaths(string $body, array $paths, string $domain): string
    {
        return (string) preg_replace_callback(
            '#<a\b[^>]*?\bhref\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a\s*>#is',
            static function (array $m) use ($paths, $domain): string {
                $href = html_entity_decode(trim($m[2]));
                $parts = parse_url($href);

                if ($parts === false) {
                    return $m[0];
                }

                if (isset($parts['host'])) {
                    if ($domain === '' || self::bareHost($parts['host']) !== $domain) {
                        return $m[0];
                    }
                } elseif (! str_starts_with($href, '/')) {
                    return $m[0];
                }

                $path = rtrim(mb_strtolower($parts['path'] ?? ''), '/');

                return isset($paths[$path]) ? $m[3] : $m[0];
            },
            $body,
        );
    }

    private static function bareHost(string $host): string
    {
        return (string) preg_replace('/^www\./i', '', mb_strtolower(trim($host)));
    }
}
