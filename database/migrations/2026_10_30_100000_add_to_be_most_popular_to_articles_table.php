<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Most popular" — the news rail's own editorial pick.
 *
 * A THIRD flag, deliberately, beside `featured` and `position`. The three answer
 * different questions and an editor sets them independently:
 *
 *   position                 order within the news feed
 *   featured                 appears in the home page's news strip
 *   to_be_most_popular       appears in the "Most Popular" rail on /news
 *
 * The rail used to reuse `featured`, which tied the two together: promoting a
 * post to the home page silently put it in the rail as well, and taking it out
 * of the rail removed it from the home page. They are different surfaces with
 * different sizes, so they get different columns.
 *
 * The name is the editor's phrasing rather than a shortening of it — the admin
 * toggle reads "Most popular", and the column says what the toggle does.
 *
 * Defaults to FALSE: nothing is in the rail until somebody puts it there.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('articles', 'to_be_most_popular')) {
            return;
        }

        Schema::table('articles', function (Blueprint $table): void {
            $table->boolean('to_be_most_popular')->default(false)->after('featured');

            /*
             * The rail's query, exactly: this site's news, flagged, visible, and
             * ordered newest first. `published_at` rides along as the last
             * column so the ORDER BY is served from the index rather than a
             * filesort — the rail is read on every /news request.
             */
            $table->index(
                ['site_id', 'type', 'to_be_most_popular', 'published_at'],
                'articles_site_type_popular_idx',
            );
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('articles', 'to_be_most_popular')) {
            return;
        }

        Schema::table('articles', function (Blueprint $table): void {
            $table->dropIndex('articles_site_type_popular_idx');
            $table->dropColumn('to_be_most_popular');
        });
    }
};
