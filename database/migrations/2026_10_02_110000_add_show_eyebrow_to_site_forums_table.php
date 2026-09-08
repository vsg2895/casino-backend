<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An explicit switch for the forum's eyebrow label.
 *
 * The original design read "clear the box to show no eyebrow", which does not
 * work: Laravel's global `ConvertEmptyStringsToNull` middleware turns the
 * submitted "" into null before validation, and null is exactly what means
 * "fall back to the shipped wording". An editor emptying the field therefore got
 * the default back — the opposite of what the screen promised.
 *
 * A boolean says the thing the empty string was being asked to imply, and it
 * cannot be swallowed in transit.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('site_forums') && ! Schema::hasColumn('site_forums', 'show_eyebrow')) {
            Schema::table('site_forums', function (Blueprint $table): void {
                $table->boolean('show_eyebrow')->default(true)->after('eyebrow');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('site_forums') && Schema::hasColumn('site_forums', 'show_eyebrow')) {
            Schema::table('site_forums', function (Blueprint $table): void {
                $table->dropColumn('show_eyebrow');
            });
        }
    }
};
