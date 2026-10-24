<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PreviewSeoTemplateRequest;
use App\Http\Requests\Admin\UpdateSeoTemplatesRequest;
use App\Jobs\RevalidateNextJsSites;
use App\Models\SeoTemplate;
use App\Models\Site;
use App\Support\Seo\SeoResolver;
use App\Support\SiteCache;
use Illuminate\Http\JsonResponse;

/**
 * Title and description patterns for one site.
 *
 * All five entity types are read and written as ONE set. They are a handful of
 * short strings that an editor tunes together — "make every title say 2026" is
 * one thought, not five — and a per-entity endpoint would turn it into five
 * requests and five chances to leave the set half-applied.
 */
class SeoTemplateController extends Controller
{
    public function index(Site $site): JsonResponse
    {
        $stored = SeoTemplate::query()
            ->where('site_id', $site->id)
            ->get()
            ->keyBy('entity');

        // Every entity is returned, configured or not, so the form always has a
        // complete set of rows to bind to.
        $data = [];

        foreach (SeoTemplate::ENTITIES as $entity) {
            $row = $stored->get($entity);

            $data[] = [
                'entity' => $entity,
                'title_pattern' => $row->title_pattern ?? null,
                'description_pattern' => $row->description_pattern ?? null,
            ];
        }

        return response()->json(['data' => $data]);
    }

    /**
     * Save the whole set.
     *
     * A pattern cleared to empty DELETES its row rather than storing "", so
     * "no pattern" has exactly one representation and the resolver never has to
     * treat an empty string as meaningful.
     */
    public function update(UpdateSeoTemplatesRequest $request, Site $site): JsonResponse
    {
        $validated = $request->validated();

        foreach ($validated['templates'] as $row) {
            $title = trim((string) ($row['title_pattern'] ?? ''));
            $description = trim((string) ($row['description_pattern'] ?? ''));

            if ($title === '' && $description === '') {
                SeoTemplate::query()
                    ->where('site_id', $site->id)
                    ->where('entity', $row['entity'])
                    ->delete();

                continue;
            }

            SeoTemplate::updateOrCreate(
                ['site_id' => $site->id, 'entity' => $row['entity']],
                [
                    'title_pattern' => $title === '' ? null : $title,
                    'description_pattern' => $description === '' ? null : $description,
                ],
            );
        }

        // A pattern change rewrites the title of every page of that type, so the
        // whole site is flushed rather than a single tag.
        SiteCache::flushSite($site->id);
        RevalidateNextJsSites::dispatch(['casinos', 'special-offers', 'categories'], [$site->id]);

        return $this->index($site);
    }

    /**
     * Render a pattern against sample values.
     *
     * The same substitution a real page uses, so an editor sees the actual
     * output — including any token they mistyped, which survives visibly rather
     * than being silently dropped.
     */
    public function preview(PreviewSeoTemplateRequest $request, Site $site, SeoResolver $resolver): JsonResponse
    {
        $validated = $request->validated();

        return response()->json([
            'preview' => $resolver->preview($site, $validated['pattern'], [
                'name' => $validated['name'] ?: 'Example Casino',
            ]),
        ]);
    }
}
