<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A second FULLTEXT index, on `title` alone, purely for RANKING.
 *
 * The combined (title, body) index answers "does this row match at all". It
 * cannot answer "did it match in the title", because MySQL scores the index as
 * one unit — so a row whose description merely mentions "games" scored the same
 * as one actually named "Game Spot Casino".
 *
 * The obvious fix, `title LIKE '%gam%'` in the SELECT, is rejected on principle:
 * this codebase does not use leading-wildcard LIKE anywhere, and a scoring
 * expression is exactly where such a thing quietly becomes an access path later.
 * A second FULLTEXT index gives a real relevance number for the title alone, at
 * the cost of one index on a table that is a rebuildable cache.
 *
 * MySQL permits multiple FULLTEXT indexes per table; MATCH() picks the one whose
 * column list it names exactly.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('search_index')) {
            return;
        }

        // MySQL only. The test harness runs on SQLite, which has neither
        // FULLTEXT nor MATCH() — SearchService detects that and serves every
        // query through its prefix path there. Production is MySQL.
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE `search_index` ADD FULLTEXT `search_index_title_fulltext` (`title`)');
    }

    public function down(): void
    {
        if (! Schema::hasTable('search_index')) {
            return;
        }

        DB::statement('ALTER TABLE `search_index` DROP INDEX `search_index_title_fulltext`');
    }
};
