<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Carries the existing "Most Popular" picks onto their own column.
 *
 * The rail used to be driven by `featured`, which also drives the home page
 * strip. Splitting them left `to_be_most_popular` false on every row, so the
 * rail would have gone empty on deploy and an editor would have had to
 * re-choose posts that were already chosen. This copies the decision across so
 * the page looks the same the moment it ships, and the two flags diverge only
 * when somebody changes one.
 *
 * A SEPARATE migration rather than a data step inside the one that added the
 * column: that one has already run on this machine, so editing it would leave
 * local and production running different code under the same name.
 *
 * NEWS ONLY. Guides have no rail, and `featured` means nothing to them — there
 * is nothing to carry over and setting the flag would be noise in a column the
 * guides screen never shows.
 *
 * Writes only where the flag is still false, so a re-run cannot undo a choice
 * made after the backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('articles')
            ->where('type', 'news')
            ->where('featured', true)
            ->where('to_be_most_popular', false)
            ->update(['to_be_most_popular' => true]);
    }

    /**
     * Deliberately does NOTHING.
     *
     * By the time anyone rolls back, `to_be_most_popular` holds the editor's own
     * picks as well as the copied ones, and the two are indistinguishable.
     * Clearing the column would destroy real editorial work to undo a copy;
     * leaving it is harmless, because the column is dropped by the migration
     * that created it if the rollback goes that far.
     */
    public function down(): void
    {
        //
    }
};
