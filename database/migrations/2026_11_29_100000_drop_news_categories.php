<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * News categories are gone.
 *
 * Every post is gambling news, so the sections split one subject into thin
 * listings and nobody filed posts into them. Removes `articles.news_category_id`
 * (its foreign key and its index first) and the `news_categories` table. The
 * posts themselves are untouched; the public feed sends ?category= URLs to /news
 * with a permanent redirect.
 *
 * Down() restores the empty structure of 2026_10_16_100000. The category rows
 * and the assignments are not recoverable from here.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('articles', 'news_category_id')) {
            Schema::table('articles', function (Blueprint $table): void {
                $table->dropForeign(['news_category_id']);
                $table->dropIndex('articles_site_type_cat_idx');
                $table->dropColumn('news_category_id');
            });
        }

        Schema::dropIfExists('news_categories');
    }

    public function down(): void
    {
        if (! Schema::hasTable('news_categories')) {
            Schema::create('news_categories', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('site_id')->constrained()->cascadeOnDelete();
                $table->string('name');
                $table->string('slug');
                $table->unsignedSmallInteger('position')->default(0);
                $table->boolean('active')->default(true);
                $table->timestamps();

                $table->unique(['site_id', 'slug']);
                $table->index(['site_id', 'active', 'position']);
            });
        }

        if (! Schema::hasColumn('articles', 'news_category_id')) {
            Schema::table('articles', function (Blueprint $table): void {
                $table->foreignId('news_category_id')
                    ->nullable()
                    ->after('type')
                    ->constrained('news_categories')
                    ->nullOnDelete();

                $table->index(['site_id', 'type', 'news_category_id'], 'articles_site_type_cat_idx');
            });
        }
    }
};
