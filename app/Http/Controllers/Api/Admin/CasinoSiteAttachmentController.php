<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AttachCasinoToSiteRequest;
use App\Http\Requests\Admin\SyncCasinoSitesRequest;
use App\Http\Requests\Admin\UpdateCasinoBylineRequest;
use App\Jobs\InvalidateCasinoCache;
use App\Services\Search\SearchIndexer;
use App\Models\Casino;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class CasinoSiteAttachmentController extends Controller
{
    public function __construct(private readonly SearchIndexer $indexer) {}

    public function index(Casino $casino): JsonResponse
    {
        $attachments = $casino->sites()
            ->get()
            ->map(fn (Site $site) => [
                'site_id'       => $site->id,
                'site_name'     => $site->name,
                'site_slug'     => $site->slug,
                'affiliate_url' => $site->pivot->affiliate_url,
                'position'      => (int) $site->pivot->position,
                'featured'      => (bool) $site->pivot->featured,
                'active'        => (bool) $site->pivot->active,
                ...$this->bylineFields($site),
            ]);

        return response()->json(['data' => $attachments]);
    }

    public function store(AttachCasinoToSiteRequest $request, Casino $casino): JsonResponse
    {
        $data = $request->validated();

        $casino->sites()->attach($data['site_id'], [
            'affiliate_url' => $data['affiliate_url'],
            'position'      => $data['position'] ?? 0,
            'featured'      => $data['featured'] ?? false,
            'active'        => $data['active'] ?? true,
        ]);

        InvalidateCasinoCache::dispatch([$data['site_id']]);
        // Pivot writes fire NO model events, so the observer cannot see this
        // change — the search index has to be told explicitly. syncCasino()
        // cascades to the casino's offers, reviews and categories, which is
        // exactly the set whose visibility a pivot change can flip.
        $this->indexer->syncCasino($casino);

        return response()->json(['data' => $this->pivotRow($casino, $data['site_id'])], 201);
    }

    public function update(AttachCasinoToSiteRequest $request, Casino $casino, Site $site): JsonResponse
    {
        $data = $request->validated();

        $casino->sites()->updateExistingPivot($site->id, [
            'affiliate_url' => $data['affiliate_url'],
            'position'      => $data['position'] ?? 0,
            'featured'      => $data['featured'] ?? false,
            'active'        => $data['active'] ?? true,
        ]);

        InvalidateCasinoCache::dispatch([$site->id]);
        // Pivot writes fire NO model events, so the observer cannot see this
        // change — the search index has to be told explicitly. syncCasino()
        // cascades to the casino's offers, reviews and categories, which is
        // exactly the set whose visibility a pivot change can flip.
        $this->indexer->syncCasino($casino);

        return response()->json(['data' => $this->pivotRow($casino, $site->id)]);
    }

    public function destroy(Casino $casino, Site $site): JsonResponse
    {
        $casino->sites()->detach($site->id);

        InvalidateCasinoCache::dispatch([$site->id]);
        // Pivot writes fire NO model events, so the observer cannot see this
        // change — the search index has to be told explicitly. syncCasino()
        // cascades to the casino's offers, reviews and categories, which is
        // exactly the set whose visibility a pivot change can flip.
        $this->indexer->syncCasino($casino);

        return response()->json(null, 204);
    }

    public function sync(SyncCasinoSitesRequest $request, Casino $casino): JsonResponse
    {
        $validated = $request->validated();
        $oldSiteIds = $casino->sites()->pluck('sites.id')->all();

        DB::transaction(function () use ($casino, $validated): void {
            $syncData = collect($validated['sites'])
                ->keyBy('site_id')
                ->map(fn (array $item) => [
                    'affiliate_url' => $item['affiliate_url'],
                    'position'      => $item['position'] ?? 0,
                    'featured'      => $item['featured'] ?? false,
                    'active'        => $item['active'] ?? true,
                ])
                ->all();

            $casino->sites()->sync($syncData);
        });

        $newSiteIds  = $casino->sites()->pluck('sites.id')->all();
        $affectedIds = array_values(array_unique([...$oldSiteIds, ...$newSiteIds]));

        if (! empty($affectedIds)) {
            InvalidateCasinoCache::dispatch($affectedIds);
        }

        // Pivot writes fire NO model events, so the observer cannot see this
        // change — the search index has to be told explicitly. syncCasino()
        // cascades to the casino's offers, reviews and categories, which is
        // exactly the set whose visibility a pivot change can flip.
        $this->indexer->syncCasino($casino);

        return response()->json(['data' => $this->index($casino)->getData()->data]);
    }

    private function pivotRow(Casino $casino, int $siteId): ?object
    {
        $site = $casino->sites()->wherePivot('site_id', $siteId)->first();

        if (! $site) {
            return null;
        }

        return (object) [
            'site_id'       => $site->id,
            'site_name'     => $site->name,
            'site_slug'     => $site->slug,
            'affiliate_url' => $site->pivot->affiliate_url,
            'position'      => (int) $site->pivot->position,
            'featured'      => (bool) $site->pivot->featured,
            'active'        => (bool) $site->pivot->active,
            ...$this->bylineFields($site),
        ];
    }

    /**
     * Set who wrote and who checked this casino's review ON ONE SITE.
     *
     * Its own endpoint rather than more columns in sync(): the attachment
     * table posts its whole state on every save, and a byline carried along
     * there would be reset by any screen that did not know to send it back.
     */
    public function updateByline(UpdateCasinoBylineRequest $request, Casino $casino, Site $site): JsonResponse
    {
        abort_unless($casino->sites()->whereKey($site->id)->exists(), 404);

        $data = $request->validated();

        $casino->sites()->updateExistingPivot($site->id, [
            'author_id'        => $data['author_id'] ?? null,
            'reviewed_by_id'   => $data['reviewed_by_id'] ?? null,
            'last_reviewed_at' => $data['last_reviewed_at'] ?? null,
        ]);

        InvalidateCasinoCache::dispatch([$site->id]);

        return response()->json(['data' => $this->pivotRow($casino, $site->id)]);
    }

    /** @return array{author_id: ?int, reviewed_by_id: ?int, last_reviewed_at: ?string} */
    private function bylineFields(Site $site): array
    {
        $reviewedAt = $site->pivot->last_reviewed_at;

        return [
            'author_id'        => $site->pivot->author_id !== null ? (int) $site->pivot->author_id : null,
            'reviewed_by_id'   => $site->pivot->reviewed_by_id !== null ? (int) $site->pivot->reviewed_by_id : null,
            'last_reviewed_at' => $reviewedAt !== null ? CarbonImmutable::parse((string) $reviewedAt)->toISOString() : null,
        ];
    }
}
