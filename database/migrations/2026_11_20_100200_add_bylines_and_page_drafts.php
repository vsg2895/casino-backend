<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who wrote and who checked each piece of content, and when it was last checked.
 *
 * Casinos are GLOBAL master data shared by every site, while authors belong to
 * one site — so a casino's byline lives on `casino_site`, the per-site row,
 * never on `casinos`. Articles and CMS pages are already per site and carry the
 * columns themselves. `nullOnDelete`: deleting an author removes the byline, not
 * the review.
 *
 * `casinos.reviewed_at` stays: it is the global date the review text was last
 * checked, and remains the fallback when a site has not recorded its own.
 *
 * The `draft_*` columns on `cms_pages` hold a revision that is NOT yet public.
 * The trust pages are already published, and switching one to `draft` to work on
 * it would 404 its live URL; a draft beside the live copy lets a rewrite wait for
 * review while the current page keeps serving. Publishing copies the draft over
 * the live columns and clears it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('casino_site') && ! Schema::hasColumn('casino_site', 'author_id')) {
            Schema::table('casino_site', function (Blueprint $table): void {
                $this->bylineColumns($table);
            });
        }

        if (Schema::hasTable('articles') && ! Schema::hasColumn('articles', 'author_id')) {
            Schema::table('articles', function (Blueprint $table): void {
                $this->bylineColumns($table);
            });
        }

        if (Schema::hasTable('cms_pages') && ! Schema::hasColumn('cms_pages', 'author_id')) {
            Schema::table('cms_pages', function (Blueprint $table): void {
                $this->bylineColumns($table);
                $table->string('draft_title')->nullable();
                $table->longText('draft_content')->nullable();
                $table->string('draft_meta_title')->nullable();
                $table->string('draft_meta_description', 500)->nullable();
                // Set when a draft is saved; null means "no pending draft".
                $table->timestamp('draft_updated_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['casino_site', 'articles', 'cms_pages'] as $tableName) {
            if (! Schema::hasColumn($tableName, 'author_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $table->dropConstrainedForeignId('author_id');
                $table->dropConstrainedForeignId('reviewed_by_id');
                $table->dropColumn('last_reviewed_at');

                if ($tableName === 'cms_pages') {
                    $table->dropColumn(['draft_title', 'draft_content', 'draft_meta_title', 'draft_meta_description', 'draft_updated_at']);
                }
            });
        }
    }

    private function bylineColumns(Blueprint $table): void
    {
        $table->foreignId('author_id')->nullable()->constrained('authors')->nullOnDelete();
        $table->foreignId('reviewed_by_id')->nullable()->constrained('authors')->nullOnDelete();
        $table->timestamp('last_reviewed_at')->nullable();
    }
};
