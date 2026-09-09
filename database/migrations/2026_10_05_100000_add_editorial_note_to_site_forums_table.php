<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An editorial note on the forum page — the site speaking in its own voice.
 *
 * WHY THIS EXISTS: a forum with no reviews yet is a bare page, and the tempting
 * fix is to invent some. Fabricated reviews attributed to people who do not
 * exist are unlawful in the markets these sites are read in, and they are the
 * one thing this feature must never make easy. This block is the honest
 * alternative: content the SITE says, attributed to the site, sitting beside
 * visitor reviews rather than pretending to be them.
 *
 * `editorial_enabled` defaults FALSE — a new column never starts publishing
 * copy on a live domain by itself.
 *
 * Attribution deliberately reuses `sites.author_*` and `byline_enabled` rather
 * than adding its own author fields: a site has ONE editorial identity, and two
 * places to set it is how they drift apart.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('site_forums')) {
            return;
        }

        Schema::table('site_forums', function (Blueprint $table): void {
            if (! Schema::hasColumn('site_forums', 'editorial_enabled')) {
                $table->boolean('editorial_enabled')->default(false)->after('show_stats');
            }
            if (! Schema::hasColumn('site_forums', 'editorial_title')) {
                $table->string('editorial_title', 180)->nullable()->after('editorial_enabled');
            }
            if (! Schema::hasColumn('site_forums', 'editorial_body')) {
                $table->text('editorial_body')->nullable()->after('editorial_title');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('site_forums')) {
            return;
        }

        Schema::table('site_forums', function (Blueprint $table): void {
            foreach (['editorial_enabled', 'editorial_title', 'editorial_body'] as $column) {
                if (Schema::hasColumn('site_forums', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
