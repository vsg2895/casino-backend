<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The UniOne cooldown is now measured in DAYS, like Warmup's.
 *
 * The column is renamed rather than repurposed so a value can never be read
 * in the wrong unit: an old row's "24" was hours, a new row's "1" is days,
 * and a column named `cooldown_hours` holding days would be a trap for the
 * next reader. Historic runs are converted on the way (rounded up, so a
 * 24-hour cooldown becomes 1 day rather than 0).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unione_sends', function (Blueprint $table): void {
            $table->renameColumn('cooldown_hours', 'cooldown_days');
        });

        DB::table('unione_sends')
            ->where('cooldown_days', '>', 0)
            ->update(['cooldown_days' => DB::raw('CEIL(cooldown_days / 24)')]);
    }

    public function down(): void
    {
        DB::table('unione_sends')->update(['cooldown_days' => DB::raw('cooldown_days * 24')]);

        Schema::table('unione_sends', function (Blueprint $table): void {
            $table->renameColumn('cooldown_days', 'cooldown_hours');
        });
    }
};
