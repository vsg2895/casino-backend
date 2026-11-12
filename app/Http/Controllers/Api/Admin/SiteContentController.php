<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateSiteContentRequest;
use App\Http\Resources\SiteContentResource;
use App\Models\Casino;
use App\Models\Site;
use App\Models\SpecialOffer;
use App\Services\SiteContentEditor;
use Illuminate\Http\JsonResponse;

/**
 * Per-site description / meta / noindex overrides for casinos and offers.
 *
 * Thin: every rule lives in SiteContentEditor, and cache invalidation in
 * SiteContentObserver.
 */
class SiteContentController extends Controller
{
    public function __construct(private readonly SiteContentEditor $editor) {}

    public function casinoIndex(Casino $casino): SiteContentResource
    {
        return new SiteContentResource($this->editor->overview($casino));
    }

    public function casinoUpdate(UpdateSiteContentRequest $request, Casino $casino, Site $site): SiteContentResource
    {
        return $this->saved($site, $this->editor->save($casino, $site, $request->validated()));
    }

    public function casinoDestroy(Casino $casino, Site $site): JsonResponse
    {
        $this->editor->clear($casino, $site);

        return response()->json(null, 204);
    }

    public function offerIndex(SpecialOffer $specialOffer): SiteContentResource
    {
        return new SiteContentResource($this->editor->overview($specialOffer));
    }

    public function offerUpdate(UpdateSiteContentRequest $request, SpecialOffer $specialOffer, Site $site): SiteContentResource
    {
        return $this->saved($site, $this->editor->save($specialOffer, $site, $request->validated()));
    }

    public function offerDestroy(SpecialOffer $specialOffer, Site $site): JsonResponse
    {
        $this->editor->clear($specialOffer, $site);

        return response()->json(null, 204);
    }

    private function saved(Site $site, mixed $row): SiteContentResource
    {
        return new SiteContentResource(['site_id' => $site->id, 'row' => $row]);
    }
}
