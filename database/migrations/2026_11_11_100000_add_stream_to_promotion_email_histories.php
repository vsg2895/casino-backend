<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which stream wrote a promotion-history row.
 *
 * Two features write to this table. The scheduled campaigns do, and so does the
 * after-verification promotion — because the table is load-bearing: its 24-hour
 * dedup is what stops a campaign and that stream both reaching one address on
 * one day. It has to see both.
 *
 * But the admin's Promotion tab reads the same table, so every
 * after-verification send also appeared under Promotion — in a section whose
 * whole point is one tab per stream, with After verification sitting right next
 * to it showing the very same rows.
 *
 * The table could not tell them apart, because nothing recorded which feature
 * wrote a row. This column does. The dedup keeps reading EVERY row, the listing
 * now reads only the campaigns, and neither has to know about the other.
 *
 * ── Why the default back-fills correctly ────────────────────────────────────
 *
 * `NOT NULL DEFAULT 'campaign'`, so every existing row is labelled the moment
 * the column exists — no UPDATE, no second pass. The labelling is right for all
 * but a handful of rows: campaigns have always been the overwhelming majority
 * of this table, and the after-verification stream only began writing to it
 * recently. The few historic rows that are mislabelled stay visible under
 * Promotion, which is exactly where they have been all along.
 *
 * ── Why ALGORITHM=INSTANT ───────────────────────────────────────────────────
 *
 * This table is RANGE-partitioned by month and is the largest in the messaging
 * subsystem. Adding a column last, with a literal default, is an INSTANT
 * operation on MySQL 8.0.29+ — no copy, no rebuild of two dozen partitions.
 * Stating it explicitly means the statement FAILS rather than silently falling
 * back to copying a multi-million-row table while campaigns are writing to it.
 */
return new class extends Migration
{
    private const string TABLE = 'promotion_email_histories';

    public function up(): void
    {
        if (Schema::hasColumn(self::TABLE, 'stream')) {
            return;
        }

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement(sprintf(
                "ALTER TABLE `%s` ADD COLUMN `stream` VARCHAR(24) NOT NULL DEFAULT 'campaign', ALGORITHM=INSTANT",
                self::TABLE,
            ));

            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->string('stream', 24)->default('campaign');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn(self::TABLE, 'stream')) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropColumn('stream');
        });
    }
};
