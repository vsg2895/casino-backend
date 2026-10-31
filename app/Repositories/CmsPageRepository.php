<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\CmsPage;
use App\Repositories\Contracts\CmsPageRepositoryInterface;
use Illuminate\Contracts\Pagination\Paginator;

class CmsPageRepository implements CmsPageRepositoryInterface
{
    /** @return Paginator<int, CmsPage> */
    public function paginate(int $perPage = 20, ?int $siteId = null): Paginator
    {
        // simplePaginate: no COUNT here, the screen's total comes from count().
        return CmsPage::query()
            ->when($siteId !== null, fn ($q) => $q->forSite($siteId))
            ->with('site')
            ->orderBy('title')
            ->simplePaginate($perPage);
    }

    public function count(?int $siteId = null): int
    {
        // The same site condition as paginate(), and nothing else.
        return CmsPage::query()
            ->when($siteId !== null, fn ($q) => $q->forSite($siteId))
            ->count();
    }

    public function findById(int $id): ?CmsPage
    {
        return CmsPage::find($id);
    }

    public function findPublishedBySlug(int $siteId, string $slug): ?CmsPage
    {
        return CmsPage::query()
            ->forSite($siteId)
            ->published()
            ->where('slug', $slug)
            // Hidden authors never reach a public byline.
            ->with(['author' => fn ($q) => $q->visible(), 'reviewer' => fn ($q) => $q->visible()])
            ->first();
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): CmsPage
    {
        return CmsPage::create($data);
    }

    /** @param array<string, mixed> $data */
    public function update(CmsPage $page, array $data): CmsPage
    {
        $page->update($data);

        return $page->refresh();
    }

    public function delete(CmsPage $page): void
    {
        $page->delete();
    }
}
