<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The cross-table pointer constraints, added last.
 *
 * A category points at its last post, that post belongs to an article, and the
 * article points back at its category. No CREATE TABLE order satisfies all
 * three, so the pointer columns were declared plain and the foreign keys wait
 * until every table exists.
 *
 * nullOnDelete everywhere, and that is the entire safety argument: deleting a
 * post must blank the pointers that referenced it, never cascade into deleting
 * the category or article that merely pointed at it. The observers then recompute
 * the pointer to the next most recent post; the FK is the backstop for the case
 * where a row is removed outside the application.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forum_categories', function (Blueprint $table): void {
            $table->foreign('last_post_id', 'forum_categories_last_post_fk')
                ->references('id')->on('forum_posts')->nullOnDelete();
            $table->foreign('last_post_user_id', 'forum_categories_last_user_fk')
                ->references('id')->on('forum_users')->nullOnDelete();
            $table->foreign('last_post_article_id', 'forum_categories_last_article_fk')
                ->references('id')->on('forum_articles')->nullOnDelete();
        });

        Schema::table('forum_articles', function (Blueprint $table): void {
            $table->foreign('last_post_id', 'forum_articles_last_post_fk')
                ->references('id')->on('forum_posts')->nullOnDelete();
            $table->foreign('last_post_user_id', 'forum_articles_last_user_fk')
                ->references('id')->on('forum_users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('forum_articles', function (Blueprint $table): void {
            $table->dropForeign('forum_articles_last_post_fk');
            $table->dropForeign('forum_articles_last_user_fk');
        });

        Schema::table('forum_categories', function (Blueprint $table): void {
            $table->dropForeign('forum_categories_last_post_fk');
            $table->dropForeign('forum_categories_last_user_fk');
            $table->dropForeign('forum_categories_last_article_fk');
        });
    }
};
