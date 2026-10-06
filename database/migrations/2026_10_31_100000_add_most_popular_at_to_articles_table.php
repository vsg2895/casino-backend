<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WHEN a post was picked for the "Most Popular" rail.
 *
 * The flag said whether a post is in the rail; nothing said when it got there,
 * so the rail could only be ordered by publication date. That is the wrong
 * question for this surface: the rail is a curated list, and what an editor
 * means by promoting something today is "this one now", not "this one, filed
 * under its own publication date". With a batch of posts sharing one import
 * date — which is exactly what the news feed looks like — ordering by
 * `published_at` left the rail in an order nobody chose.
 *
 * Written by the model when the flag is switched on, and cleared when it is
 * switched off, so it can never disagree with the flag it describes.
 *
 * EXISTING picks are backfilled from `published_at`, which is the order they
 * are in today — the column arrives without reshuffling anybody's rail.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('articles', 'most_popular_at')) {
            return;
        }

        Schema::table('articles', function (Blueprint $table): void {
            $table->timestamp('most_popular_at')->nullable()->after('to_be_most_popular');
        });

        // The order the rail is in right now, preserved.
        DB::table('articles')
            ->where('to_be_most_popular', true)
            ->whereNull('most_popular_at')
            ->update(['most_popular_at' => DB::raw('published_at')]);

        Schema::table('articles', function (Blueprint $table): void {
            /*
             * Replaces `articles_site_type_popular_idx`, which carried
             * `published_at` as its ordering column. The rail now orders by
             * `most_popular_at` first and falls back to `published_at`, so both
             * ride along and the ORDER BY is still served from the index rather
             * than a filesort — this query runs on every /news request.
             */
            $table->index(
                ['site_id', 'type', 'to_be_most_popular', 'most_popular_at', 'published_at'],
                'articles_site_type_popular_at_idx',
            );
            $table->dropIndex('articles_site_type_popular_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('articles', 'most_popular_at')) {
            return;
        }

        Schema::table('articles', function (Blueprint $table): void {
            $table->index(
                ['site_id', 'type', 'to_be_most_popular', 'published_at'],
                'articles_site_type_popular_idx',
            );
            $table->dropIndex('articles_site_type_popular_at_idx');
            $table->dropColumn('most_popular_at');
        });
    }
};
