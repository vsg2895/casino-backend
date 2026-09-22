<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Forum\ForumCategoryResource;
use App\Jobs\RevalidateNextJsSites;
use App\Models\ForumCategory;
use App\Models\ForumSection;
use App\Models\Site;
use App\Support\SiteCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sections and categories for one site's forum.
 *
 * Nested under a site, like the Forum settings screen and News Categories: the
 * taxonomy belongs to a domain, not to the network.
 *
 * Both entities are small and always edited together on one admin screen, so
 * they share a controller rather than splitting into two that would be opened
 * side by side every time.
 */
class ForumTaxonomyController extends Controller
{
    // ---------------------------------------------------------------- sections

    public function sections(Site $site): JsonResponse
    {
        $sections = ForumSection::query()
            ->where('site_id', $site->id)
            ->ordered()
            ->with(['categories' => fn ($q) => $q->orderBy('position')])
            ->get();

        return response()->json([
            'data' => $sections->map(fn (ForumSection $s): array => [
                'id'          => (int) $s->id,
                'name'        => $s->name,
                'slug'        => $s->slug,
                'description' => $s->description,
                'position'    => (int) $s->position,
                'active'      => (bool) $s->active,
                'categories'  => ForumCategoryResource::collection($s->categories)->resolve(),
            ])->all(),
        ]);
    }

    public function storeSection(Request $request, Site $site): JsonResponse
    {
        $data = $this->validateSection($request);

        $section = ForumSection::create([...$data, 'site_id' => $site->id]);

        $this->refresh($site);

        return response()->json(['data' => $this->section($section)], Response::HTTP_CREATED);
    }

    public function updateSection(Request $request, Site $site, ForumSection $forumSection): JsonResponse
    {
        $this->assertOwns($site, $forumSection->site_id);

        $forumSection->update($this->validateSection($request));

        $this->refresh($site);

        return response()->json(['data' => $this->section($forumSection)]);
    }

    /**
     * Delete a section.
     *
     * Refused while it still holds boards. Cascading would take every category,
     * every article and every post beneath them from one click on a heading —
     * a destructive action wildly out of proportion to what the button says.
     */
    public function destroySection(Site $site, ForumSection $forumSection): JsonResponse
    {
        $this->assertOwns($site, $forumSection->site_id);

        $boards = ForumCategory::query()->where('forum_section_id', $forumSection->id)->count();

        if ($boards > 0) {
            return response()->json([
                'message' => "That section still holds {$boards} board(s). Move or delete them first.",
            ], Response::HTTP_CONFLICT);
        }

        $forumSection->delete();
        $this->refresh($site);

        return response()->json(['deleted' => true]);
    }

    // -------------------------------------------------------------- categories

    public function storeCategory(Request $request, Site $site): JsonResponse
    {
        $data = $this->validateCategory($request, $site);

        $category = ForumCategory::create([...$data, 'site_id' => $site->id]);

        $this->refresh($site);

        return response()->json([
            'data' => (new ForumCategoryResource($category))->resolve(),
        ], Response::HTTP_CREATED);
    }

    public function updateCategory(Request $request, Site $site, ForumCategory $forumCategory): JsonResponse
    {
        $this->assertOwns($site, $forumCategory->site_id);

        $forumCategory->update($this->validateCategory($request, $site));

        $this->refresh($site);

        return response()->json([
            'data' => (new ForumCategoryResource($forumCategory))->resolve(),
        ]);
    }

    /** Refused while it holds articles — same reasoning as a section. */
    public function destroyCategory(Site $site, ForumCategory $forumCategory): JsonResponse
    {
        $this->assertOwns($site, $forumCategory->site_id);

        if ((int) $forumCategory->articles_count > 0) {
            return response()->json([
                'message' => "That board still holds {$forumCategory->articles_count} discussion(s). Move or delete them first.",
            ], Response::HTTP_CONFLICT);
        }

        $forumCategory->delete();
        $this->refresh($site);

        return response()->json(['deleted' => true]);
    }

    // ------------------------------------------------------------------ shared

    /** @return array<string, mixed> */
    private function validateSection(Request $request): array
    {
        return $request->validate([
            'name'        => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'position'    => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'active'      => ['sometimes', 'boolean'],
        ]);
    }

    /** @return array<string, mixed> */
    private function validateCategory(Request $request, Site $site): array
    {
        return $request->validate([
            // Must belong to THIS site: without the scoped exists rule an
            // operator could file a board under another domain's section, and
            // the denormalised site_id would then disagree with its parent.
            'forum_section_id' => [
                'required', 'integer',
                \Illuminate\Validation\Rule::exists('forum_sections', 'id')->where('site_id', $site->id),
            ],
            'name'        => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            // A short token the front end maps to one of its own icons — never
            // a path or markup. See the migration.
            'icon'        => ['nullable', 'string', 'max:40', 'regex:/^[a-z0-9-]+$/'],
            'position'    => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'active'      => ['sometimes', 'boolean'],
        ]);
    }

    /** @return array<string, mixed> */
    private function section(ForumSection $s): array
    {
        return [
            'id'          => (int) $s->id,
            'name'        => $s->name,
            'slug'        => $s->slug,
            'description' => $s->description,
            'position'    => (int) $s->position,
            'active'      => (bool) $s->active,
            'categories'  => [],
        ];
    }

    private function assertOwns(Site $site, int|string|null $ownerId): void
    {
        abort_if((int) $ownerId !== (int) $site->id, Response::HTTP_NOT_FOUND);
    }

    private function refresh(Site $site): void
    {
        SiteCache::flushSite((int) $site->id);
        RevalidateNextJsSites::dispatch(['forum'], [(int) $site->id]);
    }
}
