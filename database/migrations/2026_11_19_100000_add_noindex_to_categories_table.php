<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The editorial "hide from search engines" switch, for categories.
 *
 * Casinos, special offers, articles and CMS pages already carry `noindex`, and
 * that one column drives both the page's robots tag and the sitemap. Categories
 * were the only listed content without it. The same column rather than a
 * separate "exclude from sitemap" flag: two switches would allow a page that is
 * submitted to the sitemap while its own header refuses indexing.
 *
 * No index: the sitemap query filters categories through their casinos, which
 * are already indexed; a boolean on a table of a dozen rows would never be used.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('categories') || Schema::hasColumn('categories', 'noindex')) {
            return;
        }

        Schema::table('categories', function (Blueprint $table): void {
            $table->boolean('noindex')->default(false)->after('sort_order');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('categories', 'noindex')) {
            Schema::table('categories', function (Blueprint $table): void {
                $table->dropColumn('noindex');
            });
        }
    }
};
