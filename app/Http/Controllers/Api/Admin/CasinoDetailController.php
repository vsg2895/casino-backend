<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ImportCasinoProfilesRequest;
use App\Http\Requests\Admin\UpdateCasinoDetailRequest;
use App\Http\Resources\CasinoDetailResource;
use App\Jobs\RevalidateNextJsSites;
use App\Models\Casino;
use App\Services\CasinoProfileImportService;
use App\Support\CsvExport;
use App\Support\SiteCache;
use App\Support\Spreadsheet\CasinoProfileSheet;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The casino's factual profile, edited on its own tab.
 *
 * Deliberately SEPARATE endpoints rather than more fields on the existing casino
 * update: `CasinoController::update()` and its Form Request are working code
 * that every site depends on, and widening them to carry thirty more optional
 * fields would put the whole casino save at risk for a feature only one site
 * renders. Nothing in the existing casino CRUD changes.
 *
 * Cache and revalidation are handled here rather than left to `CasinoObserver`,
 * because saving this row does not touch the `casinos` row and therefore fires
 * none of that observer's events — a profile edit would otherwise be invisible
 * on the public site until the hourly TTL expired.
 */
class CasinoDetailController extends Controller
{
    /**
     * The profile as stored, or an all-null shell when none exists yet.
     *
     * Never 404s on a casino with no profile: the admin form needs a shape to
     * bind to, and "this casino has no profile yet" is the normal starting state
     * rather than an error.
     */
    public function show(Casino $casino): JsonResponse
    {
        $detail = $casino->detail;

        return response()->json([
            'data' => $detail === null
                ? (new CasinoDetailResource($casino->detail()->make()))->resolve()
                : (new CasinoDetailResource($detail))->resolve(),
        ]);
    }

    /**
     * Every casino's profile as one spreadsheet.
     *
     * Includes casinos with no profile yet — they come back as blank rows, which
     * is the point: the file is the work list, so an editor sees what is missing
     * rather than having to cross-reference it against the casino list.
     *
     * Ordered by name so the same file exported twice is diffable.
     */
    public function export(): StreamedResponse
    {
        $casinos = Casino::with('detail')->orderBy('name')->get(['id', 'slug', 'name']);

        return CsvExport::download(
            'casino-operator-profiles.csv',
            CasinoProfileSheet::headings(),
            $casinos->map(fn (Casino $casino): array => CasinoProfileSheet::row(
                $casino->slug,
                $casino->name,
                $casino->detail,
            )),
        );
    }

    /**
     * Apply an edited spreadsheet.
     *
     * Synchronous, and the response carries the per-row outcome. An import that
     * matched nothing returns updated=0 with the reasons listed, so "it did
     * nothing" is never indistinguishable from "it worked".
     */
    public function import(
        ImportCasinoProfilesRequest $request,
        CasinoProfileImportService $importer,
    ): JsonResponse {
        $file = $request->file('file');

        $result = $importer->import(
            $file->getRealPath(),
            $file->getClientOriginalExtension() ?: 'csv',
        );

        return response()->json($result);
    }

    public function update(UpdateCasinoDetailRequest $request, Casino $casino): JsonResponse
    {
        $detail = $casino->detail()->updateOrCreate([], $request->validated());

        // The public payload for this casino is cached per site under the
        // `casinos` tag. Flush every site the casino is attached to — the facts
        // are global, so a profile edit changes the page on all of them.
        $siteIds = $casino->sites()->pluck('sites.id')->all();

        foreach ($siteIds as $siteId) {
            SiteCache::flushSite((int) $siteId);
        }

        if ($siteIds !== []) {
            RevalidateNextJsSites::dispatch(['casinos', 'casino:' . $casino->slug], $siteIds);
        }

        return response()->json(['data' => (new CasinoDetailResource($detail->fresh()))->resolve()]);
    }
}
