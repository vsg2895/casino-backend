<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\CmsPage;
use Illuminate\Contracts\Pagination\Paginator;

interface CmsPageRepositoryInterface
{
    /**
     * One page, WITHOUT a total — the admin screen takes its total from count().
     *
     * @param  int|null  $siteId  When provided, only pages for that site.
     * @return Paginator<int, CmsPage>
     */
    public function paginate(int $perPage = 20, ?int $siteId = null): Paginator;

    /** @param  int|null  $siteId  When provided, only pages for that site. */
    public function count(?int $siteId = null): int;

    public function findById(int $id): ?CmsPage;

    public function findPublishedBySlug(int $siteId, string $slug): ?CmsPage;

    /** @param array<string, mixed> $data */
    public function create(array $data): CmsPage;

    /** @param array<string, mixed> $data */
    public function update(CmsPage $page, array $data): CmsPage;

    public function delete(CmsPage $page): void;
}
