<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WHEN an article's TEXT last changed.
 *
 * `updated_at` moves on every save, including the admin toggles that change
 * nothing a reader sees (featured, most popular, shown). It was the page's
 * `dateModified` and the sitemap's `lastmod`, so promoting a post told search
 * engines it had been rewritten. This column moves only when the title,
 * excerpt, body or cover change (Article::CONTENT_FIELDS).
 *
 * Additive. Existing rows are backfilled with the best date there is: a news
 * post's publish date (its copy is written before approval, and approval
 * restamps `published_at`), a guide's `updated_at`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('articles', 'content_updated_at')) {
            return;
        }

        Schema::table('articles', function (Blueprint $table): void {
            $table->timestamp('content_updated_at')->nullable()->after('updated_at');
        });

        DB::table('articles')->where('type', 'news')->update([
            'content_updated_at' => DB::raw('COALESCE(published_at, updated_at)'),
        ]);

        DB::table('articles')->where('type', '!=', 'news')->update([
            'content_updated_at' => DB::raw('updated_at'),
        ]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('articles', 'content_updated_at')) {
            Schema::table('articles', function (Blueprint $table): void {
                $table->dropColumn('content_updated_at');
            });
        }
    }
};
