<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The public review pages' index on `casino_reviews`.
 *
 * Both public readers filter on the same three equalities and sort on
 * `published_at`, and no existing index matched that shape:
 *
 *  - the review feed (`GET reviews`) groups `WHERE site_id = ? AND status =
 *    'published'` by `casino_id` and orders by MAX(published_at). It was served
 *    by the bare `site_id` foreign-key index, so every review on the site was
 *    read and then filtered by status before a temporary table did the grouping;
 *  - the per-casino listing and the feed's preview rows filter `casino_id, site_id,
 *    status` and order by `published_at DESC, id DESC`. `casino_reviews_public_idx`
 *    covers the filter but ends in `created_at`, so the sort was a filesort.
 *
 * Column order: the two equalities every caller shares, then `casino_id` (the
 * feed's GROUP BY and the listing's third equality), then `published_at` for the
 * sort. InnoDB appends the primary key, so the `id DESC` tie-break is index-served.
 *
 * Schema only. No data is read, written or moved, and on MySQL the index is
 * built with `ALGORITHM=INPLACE, LOCK=NONE`, so reviews keep being written while
 * it is created.
 */
return new class extends Migration
{
    private const string TABLE = 'casino_reviews';

    private const string INDEX = 'casino_reviews_site_status_casino_pub_idx';

    /** @var list<string> */
    private const array COLUMNS = ['site_id', 'status', 'casino_id', 'published_at'];

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE) || $this->indexExists()) {
            return;
        }

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement(sprintf(
                'ALTER TABLE `%s` ADD INDEX `%s` (`%s`), ALGORITHM=INPLACE, LOCK=NONE',
                self::TABLE,
                self::INDEX,
                implode('`, `', self::COLUMNS),
            ));

            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->index(self::COLUMNS, self::INDEX);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE) || ! $this->indexExists()) {
            return;
        }

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement(sprintf('ALTER TABLE `%s` DROP INDEX `%s`, ALGORITHM=INPLACE, LOCK=NONE', self::TABLE, self::INDEX));

            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropIndex(self::INDEX);
        });
    }

    private function indexExists(): bool
    {
        foreach (Schema::getIndexes(self::TABLE) as $index) {
            if (strcasecmp((string) ($index['name'] ?? ''), self::INDEX) === 0) {
                return true;
            }
        }

        return false;
    }
};
