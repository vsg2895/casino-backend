<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The casino a forum topic is about, if any.
 *
 * Optional: most topics are general and keep NULL. Set by the admin from the
 * casinos attached to the topic's site, and used to filter the admin's
 * Discussions list. A deleted casino leaves the topic in place with NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('forum_articles', 'casino_id')) {
            return;
        }

        Schema::table('forum_articles', function (Blueprint $table): void {
            $table->foreignId('casino_id')
                ->nullable()
                ->after('forum_category_id')
                ->constrained('casinos')
                ->nullOnDelete();
            $table->index(['site_id', 'casino_id'], 'forum_articles_site_casino_index');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('forum_articles', 'casino_id')) {
            return;
        }

        Schema::table('forum_articles', function (Blueprint $table): void {
            $table->dropForeign(['casino_id']);
            $table->dropIndex('forum_articles_site_casino_index');
            $table->dropColumn('casino_id');
        });
    }
};
