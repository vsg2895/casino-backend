<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\AffiliateClick;
use App\Models\Casino;
use App\Models\Site;
use App\Models\SpecialOffer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Records a click on an outbound affiliate link.
 *
 * Called SERVER-SIDE by the site's /go route handler, never from a browser. That
 * matters: this endpoint carries the site key, and a browser-side call would
 * both leak the key and let anyone inflate the numbers with a loop.
 *
 * Returns 204 and never an error the caller must handle. A failed count must not
 * cost a visitor their redirect — the click is worth less than the click-through.
 */
class AffiliateClickController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        /** @var Site $site */
        $site = app('current_site');

        $validated = $request->validate([
            'casino_slug' => ['required', 'string', 'max:255'],
            'offer_slug'  => ['nullable', 'string', 'max:255'],
        ]);

        // Scoped to casinos actually attached to THIS site, so a slug from
        // another domain's catalogue cannot be counted here.
        $casino = Casino::query()
            ->whereHas('sites', fn ($q) => $q->where('sites.id', $site->id)->where('casino_site.active', true))
            ->where('slug', $validated['casino_slug'])
            ->first(['id']);

        if ($casino === null) {
            return response()->json(null, Response::HTTP_NO_CONTENT);
        }

        $offerId = AffiliateClick::NO_OFFER;

        if (! empty($validated['offer_slug'])) {
            $offer = SpecialOffer::query()
                ->where('casino_id', $casino->id)
                ->where('slug', $validated['offer_slug'])
                ->first(['id']);

            $offerId = $offer?->id ?? AffiliateClick::NO_OFFER;
        }

        AffiliateClick::record($site->id, (int) $casino->id, (int) $offerId);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }
}
