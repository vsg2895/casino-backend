<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CmsPage;
use App\Models\Site;
use App\Repositories\Contracts\CmsPageRepositoryInterface;
use App\Support\LegalPageContent;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class CmsPageService
{
    public function __construct(
        private readonly CmsPageRepositoryInterface $repository,
    ) {}

    /** @return LengthAwarePaginator<int, CmsPage> */
    public function paginate(int $perPage = 50, ?int $siteId = null): LengthAwarePaginator
    {
        return $this->repository->paginate($perPage, $siteId);
    }

    public function findOrFail(int $id): CmsPage
    {
        return $this->repository->findById($id)
            ?? throw (new ModelNotFoundException())->setModel(CmsPage::class, [$id]);
    }

    /** Public lookup — only ever returns a published page for the given site. */
    public function getPublishedBySlugOrFail(int $siteId, string $slug): CmsPage
    {
        return $this->repository->findPublishedBySlug($siteId, $slug)
            ?? throw (new ModelNotFoundException())->setModel(CmsPage::class, [$slug]);
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): CmsPage
    {
        $data['status'] ??= CmsPage::STATUS_DRAFT;

        return $this->repository->create($data);
    }

    /** @param array<string, mixed> $data */
    public function update(CmsPage $page, array $data): CmsPage
    {
        return $this->repository->update($page, $data);
    }

    public function delete(CmsPage $page): void
    {
        $this->repository->delete($page);
    }

    /**
     * Create the full set of standard legal/informational pages for a site,
     * brand-aware (name, domain, contact emails). Idempotent: existing pages
     * (matched by site_id + slug) are left untouched so admin edits survive.
     *
     * Called by CmsPageSeeder and on new-site registration so every domain
     * automatically ships with a complete, compliant set of pages.
     *
     * @return int Number of pages created.
     */
    public function seedDefaultsForSite(Site $site): int
    {
        $created = 0;

        foreach (LegalPageContent::forBrand($site->name, $site->domain) as $page) {
            $model = CmsPage::firstOrCreate(
                ['site_id' => $site->id, 'slug' => $page['slug']],
                $page,
            );

            if ($model->wasRecentlyCreated) {
                $created++;
            }
        }

        return $created;
    }
}
