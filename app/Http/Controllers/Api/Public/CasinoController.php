<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\CasinoWithAttachmentResource;
use App\Models\Casino;
use App\Models\Site;
use App\Support\SiteCache;
use Illuminate\Http\JsonResponse;

class CasinoController extends Controller
{
    /** Relations rendered on the public site for every casino. */
    private const RELATIONS = ['categories', 'featuredSpecialOffer', 'specialOffers'];

    public function index(): JsonResponse
    {
        /** @var Site $site */
        $site = app('current_site');

        $data = SiteCache::remember($site->id, ['casinos'], 'casinos:index:site:' . $site->id, 3600, function () use ($site) {
            $casinos = $this->baseQuery($site)->orderBy('pivot.position')->get()->load(self::RELATIONS);

            return CasinoWithAttachmentResource::collection($casinos)->resolve();
        });

        return response()->json(['data' => $data]);
    }

    public function show(string $site, string $slug): JsonResponse
    {
        /** @var Site $site */
        $site = app('current_site');

        $data = SiteCache::remember($site->id, ['casinos'], 'casinos:show:site:' . $site->id . ':slug:' . $slug, 3600, function () use ($site, $slug) {
            $casino = $this->baseQuery($site)->where('casinos.slug', $slug)->firstOrFail()->load(self::RELATIONS);

            return (new CasinoWithAttachmentResource($casino))->resolve();
        });

        return response()->json(['data' => $data]);
    }

    /** @return \Illuminate\Database\Eloquent\Builder<Casino> */
    private function baseQuery(Site $site)
    {
        return Casino::query()
            ->join('casino_site as pivot', 'casinos.id', '=', 'pivot.casino_id')
            ->where('pivot.site_id', $site->id)
            ->where('pivot.active', true)
            ->where('casinos.active', true)
            ->whereNull('casinos.deleted_at')
            ->select([
                'casinos.*',
                'pivot.affiliate_url',
                'pivot.position',
                'pivot.featured',
            ]);
    }
}
