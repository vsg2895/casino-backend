<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuthorResource;
use App\Models\Article;
use App\Models\Author;
use App\Models\Casino;
use App\Models\Site;
use App\Support\SiteCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

/**
 * One author's public profile and the work they put their name to.
 *
 * 404 unless the author is VISIBLE and belongs to this site. The work listed is
 * filtered by the same rules as the pages it links to — a casino this site
 * publishes, an article that is live in a section this site has switched on —
 * so the profile can never link to a page that would 404.
 */
class AuthorController extends Controller
{
    /** Enough for a profile page; an author with more is linked from the content itself. */
    private const int MAX_ITEMS = 50;

    public function show(string $site, string $slug): JsonResponse
    {
        /** @var Site $resolved */
        $resolved = app('current_site');

        $data = SiteCache::remember(
            $resolved->id,
            ['authors'],
            'authors:show:site:' . $resolved->id . ':slug:' . $slug,
            3600,
            function () use ($resolved, $slug): array {
                $author = Author::query()
                    ->forSite($resolved->id)
                    ->visible()
                    ->where('slug', $slug)
                    ->firstOrFail();

                return [
                    'author'   => (new AuthorResource($author))->resolve(),
                    'casinos'  => $this->casinos($resolved, $author),
                    'articles' => $this->articles($resolved, $author),
                ];
            },
        );

        return response()->json(['data' => $data]);
    }

    /**
     * Casino reviews this person wrote or checked on THIS site.
     *
     * @return list<array<string, mixed>>
     */
    private function casinos(Site $site, Author $author): array
    {
        return Casino::query()
            ->join('casino_site as pivot', 'casinos.id', '=', 'pivot.casino_id')
            ->where('pivot.site_id', $site->id)
            ->where('pivot.active', true)
            ->where('casinos.active', true)
            ->where(fn (Builder $q) => $q->where('pivot.author_id', $author->id)->orWhere('pivot.reviewed_by_id', $author->id))
            ->orderBy('casinos.name')
            ->limit(self::MAX_ITEMS)
            ->get(['casinos.name', 'casinos.slug', 'casinos.image_path', 'casinos.rating', 'pivot.author_id', 'pivot.last_reviewed_at'])
            ->map(fn (Casino $casino): array => [
                'name'       => $casino->name,
                'slug'       => $casino->slug,
                'image_path' => $casino->image_path,
                'rating'     => (int) $casino->rating,
                'credit'     => (int) $casino->getAttribute('author_id') === $author->id ? 'author' : 'reviewer',
            ])
            ->values()
            ->all();
    }

    /**
     * Live guides and news this person wrote or checked, in sections this site
     * publishes — guides only once the section itself is listed.
     *
     * @return list<array<string, mixed>>
     */
    private function articles(Site $site, Author $author): array
    {
        $types = [];

        if ($site->guides_enabled
            && Article::query()->where('site_id', $site->id)->ofType(Article::TYPE_GUIDE)->visible()->count() >= Article::MIN_TO_PUBLISH_SECTION) {
            $types[] = Article::TYPE_GUIDE;
        }

        if ($site->news_enabled) {
            $types[] = Article::TYPE_NEWS;
        }

        if ($types === []) {
            return [];
        }

        return Article::query()
            ->where('site_id', $site->id)
            ->whereIn('type', $types)
            ->visible()
            ->where(fn (Builder $q) => $q->where('author_id', $author->id)->orWhere('reviewed_by_id', $author->id))
            ->orderByDesc('published_at')
            ->limit(self::MAX_ITEMS)
            ->get(['title', 'slug', 'type', 'excerpt', 'published_at', 'author_id'])
            ->map(fn (Article $article): array => [
                'title'        => $article->title,
                'slug'         => $article->slug,
                'type'         => $article->type,
                'excerpt'      => $article->excerpt,
                'published_at' => $article->published_at?->toISOString(),
                'credit'       => (int) $article->author_id === $author->id ? 'author' : 'reviewer',
            ])
            ->values()
            ->all();
    }
}
