<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreSocialLinkRequest;
use App\Http\Requests\Admin\UpdateSocialLinkRequest;
use App\Http\Resources\SocialLinkResource;
use App\Jobs\RevalidateNextJsSites;
use App\Models\SocialLink;
use App\Support\SiteCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SocialLinkController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $siteId = $request->integer('site_id') ?: null;

        $links = SocialLink::query()
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->ordered()
            ->get();

        return SocialLinkResource::collection($links);
    }

    public function store(StoreSocialLinkRequest $request): JsonResponse
    {
        $link = SocialLink::create($request->validated());

        $this->bustSite($link->site_id);

        return (new SocialLinkResource($link))->response()->setStatusCode(201);
    }

    public function update(UpdateSocialLinkRequest $request, SocialLink $socialLink): SocialLinkResource
    {
        $socialLink->update($request->validated());

        $this->bustSite($socialLink->site_id);

        return new SocialLinkResource($socialLink->fresh());
    }

    public function destroy(SocialLink $socialLink): JsonResponse
    {
        $siteId = $socialLink->site_id;
        $socialLink->delete();

        $this->bustSite($siteId);

        return response()->json(null, 204);
    }

    /** Flush the affected site's public cache and revalidate its Next.js pages. */
    private function bustSite(int $siteId): void
    {
        SiteCache::flushSite($siteId);
        RevalidateNextJsSites::dispatch(['social-links'], [$siteId]);
    }
}
