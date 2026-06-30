<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Converts CMS legal pages from global to per-site.
 *
 * Each registered site owns its own set of pages so legal/compliance content can
 * carry the correct brand name, domain, and contact details (required by email
 * providers verifying sender identity). Slugs are unique per site, not globally.
 *
 * Existing rows are placeholder seed content; they are cleared here and the full
 * production set is re-created per site by CmsPageSeeder / on site registration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cms_pages', function (Blueprint $table): void {
            $table->foreignId('site_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->dropUnique(['slug']); // slug is no longer globally unique
        });

        // Drop legacy global placeholder pages; production content is seeded per site.
        DB::table('cms_pages')->delete();

        Schema::table('cms_pages', function (Blueprint $table): void {
            $table->unique(['site_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::table('cms_pages', function (Blueprint $table): void {
            $table->dropUnique(['site_id', 'slug']);
            $table->dropConstrainedForeignId('site_id');
            $table->unique('slug');
        });
    }
};
