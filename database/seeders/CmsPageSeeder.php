<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Site;
use App\Services\CmsPageService;
use Illuminate\Database\Seeder;

/**
 * Seeds the standard legal / informational pages for every registered site,
 * brand-aware (correct name, domain, and contact addresses per site).
 *
 * Content is production-ready (see App\Support\LegalPageContent). A few
 * operator-specific facts — legal entity name, company registration, registered
 * address, and governing-law jurisdiction — remain bracketed placeholders to be
 * completed by the operator/legal counsel before launch.
 *
 * Idempotent: existing pages (per site_id + slug) are preserved, so admin edits
 * survive re-seeding.
 */
class CmsPageSeeder extends Seeder
{
    public function __construct(private readonly CmsPageService $service) {}

    public function run(): void
    {
        $total = 0;

        Site::all()->each(function (Site $site) use (&$total): void {
            $created = $this->service->seedDefaultsForSite($site);
            $total += $created;
            $this->command?->info("  [{$site->domain}]  {$created} legal pages created.");
        });

        $this->command?->info("  Seeded {$total} CMS legal pages across all sites.");
    }
}
