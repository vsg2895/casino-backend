<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The retention prune's index on `unione_webhook_events`.
 *
 * This table takes a row per delivery event UniOne posts — sent, delivered,
 * opened, clicked, bounced, complained — for every address of every run, so it
 * is the fastest-growing table in the messaging subsystem and the one the new
 * `histories:prune` has to sweep daily.
 *
 * Of its five indexes not one starts with `created_at`: they serve the webhook
 * itself (dedup by hash, lookup by job and by address). `DELETE … WHERE
 * created_at < ?` therefore read the whole table, every night, to delete the
 * oldest tail of it — which is the one query shape this table was missing.
 *
 * Schema only. No data is read, written or moved, and on MySQL the index is
 * built with `ALGORITHM=INPLACE, LOCK=NONE`, so the webhook keeps writing
 * while it is created.
 */
return new class extends Migration
{
    private const string TABLE = 'unione_webhook_events';

    private const string INDEX = 'unione_webhook_events_created_idx';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE) || $this->indexExists()) {
            return;
        }

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement(sprintf(
                'ALTER TABLE `%s` ADD INDEX `%s` (`created_at`), ALGORITHM=INPLACE, LOCK=NONE',
                self::TABLE,
                self::INDEX,
            ));

            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->index('created_at', self::INDEX);
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
