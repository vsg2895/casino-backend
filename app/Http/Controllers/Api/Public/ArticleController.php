<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\ArticleResource;
use App\Models\Article;
use App\Models\Site;
use App\Support\SiteCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * This site's published guides.
 *
 * Both routes 404 unless the site has `guides_enabled`, enforced HERE rather
 * than trusted from the advisory features endpoint — the same discipline the
 * countries and reviews endpoints follow. A site that ignores the flag still
 * gets nothing from the data.
 *
 * The listing deliberately omits `body`: twenty cards do not need twenty full
 * articles, and shipping them would make the guides index the heaviest response
 * on the site.
 */
class ArticleController extends Controller
{
    public function index(): JsonResponse
    {
        $site = $this->guidesSite();

        $data = SiteCache::remember($site->id, ['articles'], 'articles:index:site:' . $site->id, 3600, function () use ($site) {
            $articles = Article::query()
                ->where('site_id', $site->id)
                ->published()
                ->inListingOrder()
                ->get(['id', 'site_id', 'title', 'slug', 'excerpt', 'hero_image_path', 'published_at', 'position', 'updated_at']);

            return ArticleResource::collection($articles)->resolve();
        });

        return response()->json(['data' => $data]);
    }

    public function show(string $site, string $slug): JsonResponse
    {
        $resolved = $this->guidesSite();

        $data = SiteCache::remember($resolved->id, ['articles'], 'articles:show:site:' . $resolved->id . ':slug:' . $slug, 3600, function () use ($resolved, $slug) {
            $article = Article::query()
                ->where('site_id', $resolved->id)
                ->where('slug', $slug)
                ->published()
                ->firstOrFail();

            return (new ArticleResource($article))->resolve();
        });

        return response()->json(['data' => $data]);
    }

    /**
     * The current site, or a 404 when it does not publish guides.
     *
     * A 404 rather than a 403: a site without guides has no such resource, and
     * saying "forbidden" would confirm the endpoint exists for this domain.
     */
    private function guidesSite(): Site
    {
        /** @var Site $site */
        $site = app('current_site');

        abort_unless($site->guides_enabled, Response::HTTP_NOT_FOUND);

        return $site;
    }
}
