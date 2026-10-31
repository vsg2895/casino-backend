<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAuthorRequest;
use App\Http\Requests\Admin\UpdateAuthorRequest;
use App\Http\Resources\AuthorResource;
use App\Jobs\RevalidateNextJsSites;
use App\Models\Author;
use App\Models\Site;
use App\Support\SiteCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * One site's authors.
 *
 * Nested under the site, like articles: an author belongs to exactly one
 * domain. Unpaginated — a site's team is a handful of people, and the list
 * doubles as the picker behind every byline field.
 */
class AuthorController extends Controller
{
    public function index(Site $site): AnonymousResourceCollection
    {
        return AuthorResource::collection(
            Author::query()->forSite($site->id)->orderBy('name')->get(),
        );
    }

    public function store(StoreAuthorRequest $request, Site $site): JsonResponse
    {
        $author = Author::create([...$request->validated(), 'site_id' => $site->id]);

        $this->refresh($site);

        return (new AuthorResource($author))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Site $site, Author $author): AuthorResource
    {
        $this->assertBelongsTo($site, $author);

        return new AuthorResource($author);
    }

    public function update(UpdateAuthorRequest $request, Site $site, Author $author): AuthorResource
    {
        $this->assertBelongsTo($site, $author);

        $validated = $request->validated();

        // Blank means "keep it", never "clear it": the column is required and
        // only generated on creation.
        if (array_key_exists('slug', $validated) && trim((string) $validated['slug']) === '') {
            unset($validated['slug']);
        }

        // The slug is the author page's URL. Like articles, it is frozen once
        // the profile has been public; renaming is a redirect, not an edit.
        if ($author->visible && array_key_exists('slug', $validated) && $validated['slug'] !== $author->slug) {
            unset($validated['slug']);
        }

        $author->update($validated);

        $this->refresh($site);

        return new AuthorResource($author->refresh());
    }

    public function destroy(Site $site, Author $author): JsonResponse
    {
        $this->assertBelongsTo($site, $author);

        // Bylines pointing here are nulled by the foreign keys.
        $author->delete();

        $this->refresh($site);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    private function assertBelongsTo(Site $site, Author $author): void
    {
        abort_if($author->site_id !== $site->id, Response::HTTP_NOT_FOUND);
    }

    /**
     * An author appears in bylines across casinos, articles and pages, so the
     * whole site's cache goes; the revalidation sends `site:<slug>` too, which
     * every page's fetches carry.
     */
    private function refresh(Site $site): void
    {
        SiteCache::flushSite($site->id);

        if ($site->active) {
            RevalidateNextJsSites::dispatch(['authors'], [$site->id]);
        }
    }
}
