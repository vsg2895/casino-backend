<?php

declare(strict_types=1);

use App\Support\LegalPageContent;
use App\Support\SiteCache;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Decode the HTML entity the Terms page was seeded with as its title.
 *
 * {@see LegalPageContent} wrote the title as `Terms &amp; Conditions`. A title
 * is plain text — the sites render it as a React string and put it in JSON-LD —
 * so every site's terms page showed the entity itself in its <h1>. The
 * generator is fixed; this corrects the rows already seeded.
 *
 * Only the exact seeded value changes, live and draft alike, so a title an
 * editor has rewritten is left alone. Every site, not a list, and no slug is
 * hardcoded beyond the page's own.
 *
 * `updated_at` is NOT bumped: the sites show it as the policy's "Last updated"
 * date, and no word of the terms has changed. The site cache is flushed instead,
 * or the public endpoint would serve the old title for up to an hour.
 */
return new class extends Migration
{
    private const string SEEDED = 'Terms &amp; Conditions';

    private const string DECODED = 'Terms & Conditions';

    public function up(): void
    {
        // Drafts arrived with 2026_11_20_100200, which adds them conditionally.
        $hasDrafts = Schema::hasColumn('cms_pages', 'draft_title');

        $siteIds = DB::table('cms_pages')
            ->where('slug', 'terms')
            ->where(function ($q) use ($hasDrafts): void {
                $q->where('title', self::SEEDED);
                if ($hasDrafts) {
                    $q->orWhere('draft_title', self::SEEDED);
                }
            })
            ->distinct()
            ->pluck('site_id');

        DB::table('cms_pages')
            ->where('slug', 'terms')
            ->where('title', self::SEEDED)
            ->update(['title' => self::DECODED]);

        if ($hasDrafts) {
            DB::table('cms_pages')
                ->where('slug', 'terms')
                ->where('draft_title', self::SEEDED)
                ->update(['draft_title' => self::DECODED]);
        }

        foreach ($siteIds as $siteId) {
            SiteCache::flushSite((int) $siteId);
        }
    }

    /**
     * Nothing to undo: putting the entity back would only restore the bug, and
     * the decoded rows cannot be told apart from titles typed that way.
     */
    public function down(): void {}
};
