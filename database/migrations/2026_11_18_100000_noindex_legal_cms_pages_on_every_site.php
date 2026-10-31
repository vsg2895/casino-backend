<?php

declare(strict_types=1);

use App\Support\LegalPageContent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Put the six templated legal pages back out of the index on the sites the
 * 2026_11_13 backfill never saw.
 *
 * That migration set `noindex` once, on the rows that existed then. Pages
 * seeded for a site registered afterwards took the column default (false), so
 * betpulsehq, sevenstarslots and goldenspinhq served their privacy policy,
 * terms and the rest as `index, follow`: the same template text as every other
 * domain, while the sitemap left them out. Seeding now sets the flag itself
 * ({@see LegalPageContent::NOINDEX_SLUGS}); this corrects the rows already
 * written.
 *
 * Every site, not a list of three: no slug is ever hardcoded, and on the eight
 * sites the backfill reached these rows are already true, so the update is a
 * no-op there. The five trust pages are not touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('cms_pages')
            ->whereIn('slug', LegalPageContent::NOINDEX_SLUGS)
            ->where('noindex', false)
            ->update(['noindex' => true, 'updated_at' => now()]);
    }

    /**
     * Nothing to undo. Which rows were false before cannot be told apart from
     * the rows that were already true, and making a privacy policy indexable
     * is the more dangerous direction for a rollback to take.
     */
    public function down(): void {}
};
