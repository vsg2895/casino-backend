<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Public\StoreCasinoReviewRequest;
use App\Http\Resources\PublicCasinoReviewResource;
use App\Models\Casino;
use App\Models\CasinoReview;
use App\Models\Site;
use App\Support\SiteCache;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Reading and writing visitor reviews for one casino on one site.
 *
 * Both directions are gated by the site's `reviews_enabled` switch: a site that
 * does not display reviews has no reason to accept them either, and leaving the
 * write endpoint open on a site with no review UI is exactly the surface a spam
 * bot finds.
 *
 * The casino is resolved BY SLUG and checked against this site's attachments, so
 * a review cannot be posted against a casino this site does not publish.
 */
class CasinoReviewController extends Controller
{
    /** Reviews per page. Matches the category/country catalogs. */
    private const PER_PAGE = 10;

    public function index(string $site, string $casinoSlug): JsonResponse
    {
        /** @var Site $site */
        $site = app('current_site');
        $this->assertEnabled($site);

        $page = max(1, request()->integer('page', 1));
        $casino = $this->resolveCasino($site, $casinoSlug);

        $data = SiteCache::remember(
            $site->id,
            ['reviews', 'casinos'],
            'reviews:index:site:' . $site->id . ':casino:' . $casino->id . ':page:' . $page,
            3600,
            function () use ($site, $casino, $page) {
                $query = CasinoReview::query()
                    ->where('casino_id', $casino->id)
                    ->forSite($site->id)
                    ->published();

                // Aggregates over the WHOLE published set, not the page — a
                // "4.3 from 128 reviews" summary computed from ten rows would be
                // wrong on every page but the first.
                $summary = (clone $query)
                    ->selectRaw('COUNT(*) as total, AVG(rating) as average')
                    ->first();

                $paginator = $query->latest('published_at')->latest('id')
                    ->paginate(self::PER_PAGE, ['*'], 'page', $page);

                return [
                    'reviews' => PublicCasinoReviewResource::collection($paginator->getCollection())->resolve(),
                    'summary' => [
                        'total'   => (int) ($summary->total ?? 0),
                        // Rounded to one decimal for display; null when there is
                        // nothing to average, so the front end can tell "no
                        // reviews yet" from "rated 0".
                        'average' => $summary->average === null ? null : round((float) $summary->average, 1),
                    ],
                    'meta' => [
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

    /**
     * Every published review on this site, grouped by the casino it is about.
     *
     * This is what the forum page reads. It paginates CASINOS, not reviews:
     * a flat stream mixing unrelated operators is a firehose, whereas one block
     * per casino is the thing a visitor came to read.
     *
     * Ordered by most recent activity, so a casino someone reviewed today sits
     * above one that collected ten reviews last year.
     */
    public function feed(): JsonResponse
    {
        /** @var Site $site */
        $site = app('current_site');
        $this->assertEnabled($site);

        // The page's own rules, as an editor set them in the admin panel. A
        // READ, never a create — a public GET must not insert a row.
        // Read BEFORE the cache key is built: page size is one of those rules,
        // so it has to be part of the key or a changed setting would keep
        // serving the old pagination until the TTL expired.
        $settings = $site->forumSettings();

        // Two switches, both admin-controlled: the site collects reviews at all
        // (`reviews_enabled`, asserted above) and it publishes the combined
        // forum page (`enabled`, here). Off means the route does not exist.
        abort_unless($settings['enabled'], 404);

        $perPage = $settings['threads_per_page'];
        $previewCount = $settings['preview_reviews'];

        $page = max(1, request()->integer('page', 1));

        $data = SiteCache::remember(
            $site->id,
            ['reviews', 'casinos'],
            'reviews:feed:site:' . $site->id . ':page:' . $page . ':n:' . $perPage . ':p:' . $previewCount,
            3600,
            function () use ($site, $page, $perPage, $previewCount) {
                // Reviews whose casino is still published BY THIS SITE. Without
                // this the feed would keep listing operators that were detached
                // or deactivated — their casino page 404s, so the thread would
                // link nowhere.
                $visible = fn () => CasinoReview::query()
                    ->forSite($site->id)
                    ->published()
                    ->whereHas('casino', function ($q) use ($site): void {
                        $q->where('active', true)
                            ->whereHas('sites', function ($s) use ($site): void {
                                $s->where('sites.id', $site->id)->where('casino_site.active', true);
                            });
                    });

                // One row per casino. Laravel wraps a grouped query in a
                // subquery for its count, so paginate() is correct here.
                $paginator = $visible()
                    ->selectRaw('casino_id, COUNT(*) as reviews_total, AVG(rating) as reviews_average, MAX(published_at) as last_activity')
                    ->groupBy('casino_id')
                    ->orderByDesc('last_activity')
                    ->orderByDesc('reviews_total')
                    ->paginate($perPage, ['*'], 'page', $page);

                $casinos = Casino::query()
                    ->whereIn('id', $paginator->getCollection()->pluck('casino_id'))
                    ->get(['id', 'name', 'slug', 'image_path', 'banner_image'])
                    ->keyBy('id');

                $threads = [];

                foreach ($paginator->getCollection() as $row) {
                    $casino = $casinos->get($row->casino_id);

                    // A casino that vanished between the two queries. Skipping
                    // beats emitting a thread with no operator behind it.
                    if ($casino === null) {
                        continue;
                    }

                    $total = (int) $row->reviews_total;

                    // One small indexed query per casino, at most a page of
                    // them, and the whole payload is cached for an hour. A
                    // window function would save the round trips and cost far
                    // more to read.
                    $preview = $visible()
                        ->where('casino_id', $casino->id)
                        ->latest('published_at')
                        ->latest('id')
                        ->limit($previewCount)
                        ->get();

                    $threads[] = [
                        'casino' => [
                            'id'           => $casino->id,
                            'name'         => $casino->name,
                            'slug'         => $casino->slug,
                            'image_path'   => $casino->image_path,
                            'banner_image' => $casino->banner_image,
                        ],
                        'summary' => [
                            'total'   => $total,
                            'average' => $row->reviews_average === null ? null : round((float) $row->reviews_average, 1),
                            // ISO-8601 string, not Carbon: this payload goes
                            // through SiteCache, which cannot round-trip a
                            // Carbon instance.
                            'last_activity' => $row->last_activity === null
                                ? null
                                : Carbon::parse($row->last_activity)->toISOString(),
                        ],
                        'reviews' => PublicCasinoReviewResource::collection($preview)->resolve(),
                        // Drives the "read all N" link. Computed from the true
                        // total rather than the preview length, so it cannot
                        // disagree with the number beside it.
                        'has_more' => $total > $preview->count(),
                    ];
                }

                // Totals across the WHOLE site, not the page — a header reading
                // "128 reviews across 14 casinos" computed from eight rows
                // would be wrong on every page.
                $overall = $visible()
                    ->selectRaw('COUNT(*) as total, COUNT(DISTINCT casino_id) as casinos, AVG(rating) as average')
                    ->first();

                return [
                    'threads' => $threads,
                    'summary' => [
                        'total'   => (int) ($overall->total ?? 0),
                        'casinos' => (int) ($overall->casinos ?? 0),
                        'average' => $overall?->average === null ? null : round((float) $overall->average, 1),
                    ],
                    'meta' => [
                        'current_page' => $paginator->currentPage(),
                        'last_page'    => $paginator->lastPage(),
                        'per_page'     => $paginator->perPage(),
                        'total'        => $paginator->total(),
                    ],
                ];
            },
        );

        // Merged OUTSIDE the cached closure. The wording an editor types must
        // appear the moment they save, and the settings row is a single indexed
        // read — caching it would trade nothing for an hour of staleness.
        $data['settings'] = $settings;

        return response()->json(['data' => $data]);
    }

    /**
     * Accept a review. It is stored PENDING and is not visible to anyone until an
     * admin publishes it.
     *
     * The response deliberately says only that it was received. Returning the
     * stored row would let a submitter confirm their text was kept, and returning
     * its id would expose a sequence of every review across every site.
     */
    public function store(StoreCasinoReviewRequest $request, string $site, string $casinoSlug): JsonResponse
    {
        /** @var Site $site */
        $site = app('current_site');
        $this->assertEnabled($site);

        $casino = $this->resolveCasino($site, $casinoSlug);

        CasinoReview::create([
            ...$request->validated(),
            'site_id'   => $site->id,
            'casino_id' => $casino->id,
            // Set HERE, never from the request. This line is the moderation
            // guarantee — see StoreCasinoReviewRequest.
            'status'    => CasinoReview::STATUS_PENDING,
        ]);

        // No cache flush: a pending review changes nothing that is publicly
        // visible. The flush happens when an admin publishes it.

        return response()->json([
            'ok'      => true,
            'message' => 'Thanks — your review has been submitted and will appear once approved.',
        ], Response::HTTP_ACCEPTED);
    }

    /**
     * The casino, but only if THIS site publishes it.
     *
     * Without the attachment check, any site's key would let someone attach
     * reviews to casinos that site does not carry — and those reviews would then
     * be invisible to the moderator filtering by site.
     */
    private function resolveCasino(Site $site, string $slug): Casino
    {
        return Casino::query()
            ->where('slug', $slug)
            ->where('active', true)
            ->whereHas('sites', function ($q) use ($site): void {
                $q->where('sites.id', $site->id)->where('casino_site.active', true);
            })
            ->firstOrFail();
    }

    /**
     * 404 when this site does not publish reviews — matching how a disabled
     * countries filter and an inactive site are treated.
     */
    private function assertEnabled(Site $site): void
    {
        abort_unless((bool) $site->reviews_enabled, 404);
    }
}
