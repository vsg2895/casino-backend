<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Best news" — the editorial pick that surfaces on the home page.
 *
 * Separate from `position`, which is the order WITHIN the news section. A post
 * can be first in the feed without deserving a slot on the home page, and a post
 * worth putting on the home page is not necessarily the newest one. Overloading
 * position to mean both would make the two decisions fight each other: promoting
 * something to the front page would silently reorder the feed.
 *
 * Column is `featured`, matching the name this codebase already uses for the
 * same idea on `casino_site.featured` and `casinos.featured_special_offer_id`.
 * The admin labels it "Best news", which is what an editor calls it.
 *
 * Defaults to FALSE: nothing is promoted until somebody chooses it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('articles', 'featured')) {
            return;
        }

        Schema::table('articles', function (Blueprint $table): void {
            $table->boolean('featured')->default(false)->after('active');

            // The home-page query is (site, type, featured) filtered to the
            // visible ones — this is that query's index.
            $table->index(['site_id', 'type', 'featured'], 'articles_site_type_featured_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('articles', 'featured')) {
            return;
        }

        Schema::table('articles', function (Blueprint $table): void {
            $table->dropIndex('articles_site_type_featured_idx');
            $table->dropColumn('featured');
        });
    }
};
