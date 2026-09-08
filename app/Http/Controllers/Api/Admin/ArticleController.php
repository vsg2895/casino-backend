<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreArticleRequest;
use App\Http\Requests\Admin\UpdateArticleRequest;
use App\Http\Resources\ArticleResource;
use App\Jobs\RevalidateNextJsSites;
use App\Models\Article;
use App\Models\Site;
use App\Support\SiteCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Guides for one site.
 *
 * Nested under the site because an article belongs to exactly one domain — the
 * only content type in this application for which that is true.
 *
 * The admin listing shows DRAFTS as well as published articles; the public one
 * cannot. That asymmetry is the point of having a draft state at all.
 */
class ArticleController extends Controller
{
    public function index(Site $site): AnonymousResourceCollection
    {
        return ArticleResource::collection(
            Article::query()
                ->where('site_id', $site->id)
                ->orderBy('position')
                ->orderByDesc('published_at')
                ->orderByDesc('id')
                ->get(['id', 'site_id', 'title', 'slug', 'excerpt', 'hero_image_path', 'published_at', 'position', 'noindex', 'updated_at']),
        );
    }

    public function show(Site $site, Article $article): JsonResponse
    {
        $this->assertBelongsTo($site, $article);

        return response()->json(['data' => (new ArticleResource($article))->resolve()]);
    }

    public function store(StoreArticleRequest $request, Site $site): JsonResponse
    {
        $article = Article::create([...$request->validated(), 'site_id' => $site->id]);

        $this->refresh($site, $article);

        return (new ArticleResource($article))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateArticleRequest $request, Site $site, Article $article): JsonResponse
    {
        $this->assertBelongsTo($site, $article);

        $validated = $request->validated();

        // The slug is in the public URL. Changing it on a PUBLISHED article
        // breaks whatever ranked there, so it is ignored once live — renaming is
        // a new slug plus a redirect, which the Redirects screen exists for.
        if ($article->published_at !== null) {
            unset($validated['slug']);
        }

        $article->update($validated);

        $this->refresh($site, $article);

        return response()->json(['data' => (new ArticleResource($article))->resolve()]);
    }

    public function destroy(Site $site, Article $article): JsonResponse
    {
        $this->assertBelongsTo($site, $article);

        $article->delete();
        $this->refresh($site, $article);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    private function assertBelongsTo(Site $site, Article $article): void
    {
        abort_if($article->site_id !== $site->id, Response::HTTP_NOT_FOUND);
    }

    /**
     * One site only — guides are never shared, so there is no fan-out.
     *
     * Both tags are sent: the listing changes whenever any article does, and the
     * detail page changes for this one.
     */
    private function refresh(Site $site, Article $article): void
    {
        SiteCache::flushSite($site->id);
        RevalidateNextJsSites::dispatch(['articles', 'article:' . $article->slug], [$site->id]);
    }
}
