<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Adds a second opaque unsubscribe token so each subscriber has an independent,
 * PII-free token per email stream:
 *   - `unsubscribe_token`            → subscription stream (already present)
 *   - `promotion_unsubscribe_token`  → promotion stream (added here)
 *
 * Existing rows are backfilled so every subscriber can be unsubscribed from
 * either stream immediately.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('newsletters', function (Blueprint $table): void {
            $table->string('promotion_unsubscribe_token', 64)
                ->nullable()
                ->unique()
                ->after('unsubscribe_token');
        });

        // Backfill existing subscribers (incl. soft-deleted) with a fresh token.
        DB::table('newsletters')
            ->whereNull('promotion_unsubscribe_token')
            ->orderBy('id')
            ->each(function (object $row): void {
                DB::table('newsletters')
                    ->where('id', $row->id)
                    ->update(['promotion_unsubscribe_token' => Str::random(64)]);
            });
    }

    public function down(): void
    {
        Schema::table('newsletters', function (Blueprint $table): void {
            $table->dropUnique(['promotion_unsubscribe_token']);
            $table->dropColumn('promotion_unsubscribe_token');
        });
    }
};
