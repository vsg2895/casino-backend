<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * News, as a second KIND of article rather than a second table.
 *
 * `articles` already carries every column a news post needs — title, slug,
 * excerpt, body, hero image, published_at, position, the SEO trio and soft
 * deletes — and already has per-site scoping, an admin CRUD, a public feed and a
 * detail endpoint. A parallel `news` table would have duplicated all of it, and
 * then every later change (a new SEO field, a revalidation tag, an editor
 * screen) would have had to be made twice and would eventually have been made
 * once.
 *
 * So the discriminator: `type` is 'guide' or 'news'. Everything that queries
 * articles now says which kind it means, and the two feeds cannot leak into each
 * other.
 *
 * `default('guide')` is what makes this safe on a live table: every existing row
 * IS a guide, and any code not yet updated keeps seeing exactly what it saw.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('articles', 'type')) {
            Schema::table('articles', function (Blueprint $table): void {
                $table->string('type', 16)->default('guide')->after('site_id');

                // The listing query is always (site, type, published_at) — this
                // is that query's index, not a general-purpose one on `type`,
                // which on two distinct values would barely be worth reading.
                $table->index(['site_id', 'type', 'published_at'], 'articles_site_type_published_idx');
            });

            // Belt and braces for rows written between the ALTER and the deploy.
            DB::table('articles')->whereNull('type')->update(['type' => 'guide']);
        }

        if (! Schema::hasColumn('sites', 'news_enabled')) {
            Schema::table('sites', function (Blueprint $table): void {
                // Off by default, like every other per-site feature flag: a new
                // domain must opt in rather than inherit a section it has no
                // content for.
                $table->boolean('news_enabled')->default(false)->after('guides_enabled');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('articles', 'type')) {
            Schema::table('articles', function (Blueprint $table): void {
                $table->dropIndex('articles_site_type_published_idx');
                $table->dropColumn('type');
            });
        }

        if (Schema::hasColumn('sites', 'news_enabled')) {
            Schema::table('sites', function (Blueprint $table): void {
                $table->dropColumn('news_enabled');
            });
        }
    }
};
