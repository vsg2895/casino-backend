<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\CmsPage;
use App\Repositories\Contracts\CmsPageRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class CmsPageRepository implements CmsPageRepositoryInterface
{
    /** @return LengthAwarePaginator<int, CmsPage> */
    public function paginate(int $perPage = 20, ?int $siteId = null): LengthAwarePaginator
    {
        return CmsPage::query()
            ->when($siteId !== null, fn ($q) => $q->forSite($siteId))
            ->with('site')
            ->orderBy('title')
            ->paginate($perPage);
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
