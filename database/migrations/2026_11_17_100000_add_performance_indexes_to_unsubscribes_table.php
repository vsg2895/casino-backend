<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Unsubscribes screen filtered by SITE ALONE.
 *
 * The admin listing and its CSV export are `WHERE site_id = ? ORDER BY
 * unsubscribed_at DESC`, with the stream filter optional. The existing indexes
 * cover the other two shapes:
 *
 *  - no filter: `unsubscribes_listing_idx (unsubscribed_at, id)`;
 *  - site AND stream: `unsubscribes_site_type_time_index (site_id, type,
 *    unsubscribed_at)`.
 *
 * With a site and no stream, `type` sits between the equality and the sort
 * column, so neither can order the rows. EXPLAIN reports an index lookup on
 * `site_id` followed by `Sort: unsubscribes.unsubscribed_at DESC`: every opt-out
 * of that site is read and sorted to show one page of 50. This table grows for
 * as long as the platform sends mail.
 *
 * Column order: the equality, then the sort column. InnoDB appends the primary
 * key, so the index is effectively `(site_id, unsubscribed_at, id)`.
 *
 * Schema only. No data is read, written or moved, and on MySQL the index is
 * built with `ALGORITHM=INPLACE, LOCK=NONE`, so opt-outs keep being written while
 * it is created. Writes to this table are one row per opt-out, so the extra
 * index costs next to nothing.
 */
return new class extends Migration
{
    private const string TABLE = 'unsubscribes';

    private const string INDEX = 'unsubscribes_site_time_idx';

    /** @var list<string> */
    private const array COLUMNS = ['site_id', 'unsubscribed_at'];

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
