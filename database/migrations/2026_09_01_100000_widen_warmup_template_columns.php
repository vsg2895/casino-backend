<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widens the warmup `template` columns from 20 to 40 characters.
 *
 * Production fix for:
 *   SQLSTATE[22001] Data too long for column 'template' at row 1
 *   … insert into `warmup_sends` … values (2, 1, promotion_after_verification, …)
 *
 * Both columns were sized when the only warmup templates were `subscribe` (9) and
 * `promotion` (9). `promotion_after_verification` is 28, so selecting it in the
 * send dialog failed at the INSERT — after the run had already been counted and
 * the audience resolved.
 *
 * 40 matches `unsubscribes.type`, widened in 2026_08_24_110000 for the same value.
 * That is the length {@see \App\Models\WarmupSend::TEMPLATE_MAX_LENGTH} publishes,
 * and a test asserts every key in the catalog fits it.
 *
 * WHY THE TEST SUITE MISSED IT: the suite runs on SQLite, which does not enforce
 * VARCHAR length — an over-long string is stored silently. Only MySQL rejects it,
 * so no amount of feature testing on SQLite would have caught this. The guard
 * added alongside is therefore a length ASSERTION rather than an insert.
 *
 * Widening only: no data is truncated and no value changes.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const array TABLES = ['warmup_sends', 'warmup_send_recipients'];

    public function up(): void
    {
        $this->resize(40);
    }

    public function down(): void
    {
        // Rows written while this was applied may hold values longer than 20, so
        // shrinking would truncate them. Clear those first rather than silently
        // corrupting the audit.
        foreach (self::TABLES as $table) {
            \Illuminate\Support\Facades\DB::table($table)
                ->whereRaw('CHAR_LENGTH(template) > 20')
                ->update(['template' => '']);
        }

        $this->resize(20);
    }

    private function resize(int $length): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($length): void {
                // Restated verbatim: ->change() redefines the whole column, so the
                // type and nullability have to be repeated or they are lost.
                $blueprint->string('template', $length)->change();
            });
        }
    }
};
