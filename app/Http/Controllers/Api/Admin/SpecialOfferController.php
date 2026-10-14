<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreSpecialOfferRequest;
use App\Http\Requests\Admin\UpdateSpecialOfferRequest;
use App\Http\Resources\SpecialOfferResource;
use App\Jobs\InvalidateCasinoCache;
use App\Models\SpecialOffer;
use App\Services\OfferSlugRedirectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SpecialOfferController extends Controller
{
    /**
     * The casino's attached sites, restricted to the columns the resource reads.
     *
     * `CasinoResource` renders exactly five site fields — id, name, slug,
     * domain, and `revalidation_url` (through `adminLinkBaseUrl()`, which
     * prefers the configured revalidation host over the public domain) — plus
     * the pivot, which Eloquent appends on its own.
     *
     * Naming them matters here because this listing is UNPAGINATED and the
     * relation is per casino: a `SELECT *` fetched `settings` (JSON),
     * `author_bio` and `last_revalidation_error` (TEXT) for every
     * casino-to-site row behind every offer on the screen, none of which any
     * response returns. Qualified with the table name because the relation is
     * read over a join.
     *
     * AdminListingPerformanceTest asserts the rendered site block is identical
     * to the one a `SELECT *` produced, pivot values included.
     */
    private const string SITE_COLUMNS =
        'casino.sites:sites.id,sites.name,sites.slug,sites.domain,sites.revalidation_url';

    public function index(): AnonymousResourceCollection
    {
        /*
         * Both relations are eager-loaded because the resource renders both for
         * every row:
         *
         *  - `casino.sites` so the admin can build each offer's public
         *    single-page link (https://{site domain}/special-offers/{slug});
         *  - `bonusCategory` because `bonus_category_slug` is rendered
         *    unconditionally, NOT behind whenLoaded — without it this listing
         *    issues one SELECT against bonus_categories per offer, which is the
         *    whole screen's cost once the catalogue is a few hundred offers.
         */
        return SpecialOfferResource::collection(
            SpecialOffer::with([self::SITE_COLUMNS, 'bonusCategory'])->latest()->get()
        );
    }

    /**
     * Total special offers, as a dedicated COUNT.
     *
     * Kept off the listing request on purpose: that query eager-loads
     * casino.sites and orders by created_at, and neither has any bearing on the
     * total. This is a bare COUNT against the primary table (soft-deleted rows
     * excluded by the model's global scope).
     */
    public function count(): JsonResponse
    {
        return response()->json(['total' => SpecialOffer::query()->count()]);
    }

    public function store(StoreSpecialOfferRequest $request): SpecialOfferResource
    {
        $offer = SpecialOffer::create($request->validated());

        $this->revalidate($offer);

        return new SpecialOfferResource($offer->load(['casino.sites', 'bonusCategory']));
    }

    public function show(SpecialOffer $specialOffer): SpecialOfferResource
    {
        return new SpecialOfferResource($specialOffer->load(['casino.sites', 'bonusCategory']));
    }

    public function update(
        UpdateSpecialOfferRequest $request,
        SpecialOffer $specialOffer,
        OfferSlugRedirectService $redirects,
    ): SpecialOfferResource {
        // Captured BEFORE the write. The slug can move — an editor may set a new
        // one, or clear the field to have it regenerated from the title — and the
        // casino may be reassigned, so after update() the old detail-page tag and
        // the previous casino's sites are unreachable, and the pages they back
        // would keep serving the stale offer for the rest of the ISR window.
        $previousSiteIds = $this->siteIdsFor($specialOffer);
        $previousSlug = (string) $specialOffer->slug;

        $specialOffer->update($request->validated());

        /*
         * The address may have moved — the slug follows the title, and the
         * admin can also set one by hand. Either way the OLD path has to keep
         * working: links live in inboxes, chats and search results, and a
         * rename must not turn them into 404s.
         *
         * Both sets of sites are passed: a save can re-attach the offer's
         * casino, and the rule belongs on the sites that could have served the
         * old URL as well as the ones that will serve the new one.
         */
        $redirects->record(
            $specialOffer,
            $previousSlug,
            [...$previousSiteIds, ...$this->siteIdsFor($specialOffer)],
        );

        $this->revalidate($specialOffer, $previousSiteIds, [$previousSlug]);

        return new SpecialOfferResource($specialOffer->fresh(['casino.sites', 'bonusCategory']));
    }

    public function destroy(SpecialOffer $specialOffer): JsonResponse
    {
        // Resolve the audience first, delete, THEN revalidate. Pinging before the
        // delete lets Next.js regenerate the affected pages while the offer is
        // still readable, which re-caches the very rows this request removes.
        $siteIds = $this->siteIdsFor($specialOffer);
        $slug = (string) $specialOffer->slug;

        $specialOffer->delete();

        $this->dispatchInvalidation($siteIds, [$slug]);

        return response()->json(null, 204);
    }

    public function duplicate(SpecialOffer $specialOffer): SpecialOfferResource
    {
        $copy = $specialOffer->replicate(['slug']);
        $copy->title = $specialOffer->title . ' (Copy)';
        $copy->slug = null; // regenerated by the model's saving hook
        $copy->save();

        $this->revalidate($copy);

        return new SpecialOfferResource($copy->load(['casino.sites', 'bonusCategory']));
    }

    /**
     * Sites this offer is published on, via its casino's attachments.
     *
     * @return list<int>
     */
    private function siteIdsFor(SpecialOffer $offer): array
    {
        return $offer->casino?->sites()->pluck('sites.id')->all() ?? [];
    }

    /**
     * @param  list<int>     $alsoSiteIds  Sites the offer was on BEFORE this write.
     * @param  list<string>  $alsoSlugs    Detail-page slugs it answered to before.
     */
    private function revalidate(SpecialOffer $offer, array $alsoSiteIds = [], array $alsoSlugs = []): void
    {
        $this->dispatchInvalidation(
            [...$this->siteIdsFor($offer), ...$alsoSiteIds],
            [(string) $offer->slug, ...$alsoSlugs],
        );
    }

    /**
     * @param  list<int>     $siteIds
     * @param  list<string>  $slugs
     */
    private function dispatchInvalidation(array $siteIds, array $slugs): void
    {
        $siteIds = array_values(array_unique(array_filter($siteIds)));

        if ($siteIds === []) {
            return;
        }

        // Every surface an offer appears on, or a visibility toggle would
        // keep showing the card for up to an hour of stale ISR:
        //  - casinos          → the offer cards on the casino page
        //  - special-offers   → the home-page section and /special-offers
        //  - special-offer:*  → the offer's own detail page
        $slugTags = array_map(
            static fn (string $slug): string => 'special-offer:' . $slug,
            array_values(array_unique(array_filter($slugs, static fn (string $s): bool => $s !== ''))),
        );

        InvalidateCasinoCache::dispatch($siteIds, ['casinos', 'special-offers', ...$slugTags]);
    }
}
