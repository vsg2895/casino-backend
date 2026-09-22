<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A show/hide switch for articles, separate from the publish date.
 *
 * `published_at` already decides visibility — null is a draft, a future date is
 * scheduled — but it is the wrong control for "take this down for now". Hiding a
 * post by clearing its date DESTROYS the date, and putting it back tomorrow
 * silently re-dates a month-old article to today. Editors then avoid the control
 * and hide things by deleting them instead, which loses the post entirely.
 *
 * So the two concerns get two fields, the way they already are elsewhere in this
 * schema: `active` on casinos, countries and nav_items all mean exactly this.
 * `published_at` stays the editorial date and the scheduler; `active` is the
 * switch, and flipping it costs nothing.
 *
 * Defaults to TRUE so every existing article keeps its current visibility.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('articles', 'active')) {
            return;
        }

        Schema::table('articles', function (Blueprint $table): void {
            $table->boolean('active')->default(true)->after('position');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('articles', 'active')) {
            return;
        }

        Schema::table('articles', function (Blueprint $table): void {
            $table->dropColumn('active');
        });
    }
};
