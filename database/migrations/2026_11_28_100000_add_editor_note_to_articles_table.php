<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An editor's own comment on a news post, shown on the page as "Editor's note".
 *
 * The rewrite can only restate a source's facts and the site's records; this is
 * where a person says what the story means for this site's players. It is
 * plain text, written in the admin, never generated and never touched by
 * `news:rewrite`. Its words count toward the indexing bar (NewsIndexGate).
 *
 * Additive and nullable: no backfill, nothing changes on any page until a note
 * is written.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('articles', 'editor_note')) {
            return;
        }

        Schema::table('articles', function (Blueprint $table): void {
            $table->text('editor_note')->nullable()->after('body');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('articles', 'editor_note')) {
            return;
        }

        Schema::table('articles', function (Blueprint $table): void {
            $table->dropColumn('editor_note');
        });
    }
};
