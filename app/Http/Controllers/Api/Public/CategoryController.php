<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\CasinoWithAttachmentResource;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Models\Site;
use App\Support\SiteCache;
use Illuminate\Http\JsonResponse;

class CategoryController extends Controller
{
    /** Casinos shown per page within a category. */
    private const PER_PAGE = 4;

    public function index(): JsonResponse
    {
        /** @var Site $site */
        $site = app('current_site');

        $data = SiteCache::remember($site->id, ['categories', 'casinos'], 'categories:index:site:' . $site->id, 3600, function () use ($site) {
            // Only categories that have at least one active casino attached to THIS site,
            // ordered by priority (sort_order), each with a per-site casino count.
            $attachedToSite = function ($query) use ($site): void {
                $query->where('casinos.active', true)
                    ->whereHas('sites', function ($s) use ($site): void {
                        $s->where('sites.id', $site->id)->where('casino_site.active', true);
                    });
            };

            $categories = Category::query()
                ->whereHas('casinos', $attachedToSite)
                ->withCount(['casinos as casinos_count' => $attachedToSite])
                ->ordered()
                ->get();

            return CategoryResource::collection($categories)->resolve();
        });

        return response()->json(['data' => $data]);
    }

    public function show(string $site, string $slug): JsonResponse
    {
        /** @var Site $site */
        $site = app('current_site');
        $page = max(1, request()->integer('page', 1));

        $data = SiteCache::remember(
            $site->id,
            ['categories', 'casinos'],
            'categories:show:site:' . $site->id . ':slug:' . $slug . ':page:' . $page,
            3600,
            function () use ($site, $slug, $page) {
                $category = Category::where('slug', $slug)->firstOrFail();

                $paginator = $category->casinos()
                    ->join('casino_site as pivot', 'casinos.id', '=', 'pivot.casino_id')
                    ->where('pivot.site_id', $site->id)
                    ->where('pivot.active', true)
                    ->where('casinos.active', true)
                    ->orderBy('pivot.position')
                    ->select([
                        'casinos.*',
                        'pivot.affiliate_url',
                        'pivot.position',
                        'pivot.featured',
                    ])
                    ->paginate(self::PER_PAGE, ['*'], 'page', $page);

                $paginator->getCollection()->load(['categories', 'featuredSpecialOffer']);

                return [
                    'category' => (new CategoryResource($category))->resolve(),
                    'casinos'  => CasinoWithAttachmentResource::collection($paginator->getCollection())->resolve(),
                    'meta'     => [
                        'current_page' => $paginator->currentPage(),
                        'last_page'    => $paginator->lastPage(),
                        'per_page'     => $paginator->perPage(),
                        'total'        => $paginator->total(),
                    ],
                ];
            },
        );

        return response()->json(['data' => $data]);
    }
}
