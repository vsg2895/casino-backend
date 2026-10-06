<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drops `articles.position` — both sections are now ordered by date.
 *
 * News went first: a feed's order IS its chronology, and a hand-set position
 * silently pinned an older story above a newer one. Guides follow for a quieter
 * reason — in practice every guide carried the position its seeder gave it, so
 * the column was a control nobody used that still had to be explained in the
 * admin, validated on two requests, carried in three column lists and reasoned
 * about in every ordering decision.
 *
 * What replaces it: `published_at DESC, id DESC`, everywhere. An editor who
 * wants a guide higher up moves its date, which is a thing the screen already
 * shows and a reader can make sense of.
 *
 * IRREVERSIBLE in the sense that matters: `down()` puts the column back, but
 * every value is gone and each row returns to the default 0. That is why the
 * ordering it fed is replaced here rather than left to chance.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('articles', 'position')) {
            return;
        }

        Schema::table('articles', function (Blueprint $table): void {
            $table->dropColumn('position');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('articles', 'position')) {
            return;
        }

        Schema::table('articles', function (Blueprint $table): void {
            // Back as the column was, but empty: the arrangement it held is not
            // recoverable from anything else in the row.
            $table->unsignedInteger('position')->default(0)->after('published_at');
        });
    }
};
