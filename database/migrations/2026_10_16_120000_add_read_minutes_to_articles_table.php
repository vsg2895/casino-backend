<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reading time, stored rather than computed on read.
 *
 * It is derived from the body, and the body is deliberately NOT selected by the
 * listing query — a feed of twelve cards has no business shipping twelve full
 * articles to print "3 min read" on each. Computing it at WRITE time keeps the
 * listing payload exactly as small as it was and costs one integer per row.
 *
 * Nullable, not defaulted to zero: null means "not calculated" (an article
 * saved before this column existed and never re-saved), and the card then shows
 * nothing rather than claiming a confident "0 min read". The backfill below
 * settles that for every row that exists today; Article's saving hook keeps it
 * current from here.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('articles', 'read_minutes')) {
            Schema::table('articles', function (Blueprint $table): void {
                $table->unsignedSmallInteger('read_minutes')->nullable()->after('excerpt');
            });
        }

        $this->backfill();
    }

    /**
     * Count what is already there.
     *
     * Done in SQL-fed chunks rather than by loading every article: the same
     * word count the model applies, but without instantiating the whole table.
     */
    private function backfill(): void
    {
        DB::table('articles')
            ->select('id', 'body')
            ->orderBy('id')
            ->chunk(200, function ($rows): void {
                foreach ($rows as $row) {
                    $text = trim(html_entity_decode(strip_tags((string) $row->body)));
                    $words = $text === '' ? 0 : count(preg_split('/\s+/', $text) ?: []);

                    DB::table('articles')
                        ->where('id', $row->id)
                        // 200 words per minute, and never zero for an article
                        // that has any words at all.
                        ->update(['read_minutes' => $words === 0 ? null : max(1, (int) round($words / 200))]);
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasColumn('articles', 'read_minutes')) {
            Schema::table('articles', function (Blueprint $table): void {
                $table->dropColumn('read_minutes');
            });
        }
    }
};
