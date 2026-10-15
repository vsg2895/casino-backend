<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Marks a history row as a "Send test" rather than a real send.
 *
 * The test buttons on Promotion After Verification and UniOne now record what
 * they sent — before this, pressing test produced an email and no evidence,
 * which is the one case where evidence is most wanted (the operator is testing
 * precisely because something is in doubt).
 *
 * But a test is not a subscriber being mailed, and a history that cannot tell
 * the two apart stops being an audit trail: "did we mail this person" would be
 * answered by a row that went to the operator's own address. Hence the flag,
 * and hence the admin showing it as a tag rather than hiding it.
 *
 * `NOT NULL DEFAULT false`, so every existing row is correct the moment the
 * column exists: nothing before this migration was a test, because nothing
 * before it recorded one.
 *
 * On MySQL both are added with `ALGORITHM=INSTANT` — a column appended with a
 * literal default needs no table rebuild, and `unione_sends` carries two
 * LONGTEXT bodies per row that there is no reason to copy.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const array TABLES = ['verification_promotion_sends', 'unione_sends'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'is_test')) {
                continue;
            }

            if (DB::connection()->getDriverName() === 'mysql') {
                DB::statement(sprintf(
                    'ALTER TABLE `%s` ADD COLUMN `is_test` TINYINT(1) NOT NULL DEFAULT 0, ALGORITHM=INSTANT',
                    $table,
                ));

                continue;
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->boolean('is_test')->default(false);
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'is_test')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn('is_test');
            });
        }
    }
};
