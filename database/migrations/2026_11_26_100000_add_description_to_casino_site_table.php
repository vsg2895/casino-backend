<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * This site's own review text for a casino (SEO audit H6).
 *
 * `casinos.description` is global master data, so every site carrying a
 * casino printed the same review word for word, and search engines index one
 * copy and filter the rest as duplicates. An editor writes a site's own text
 * here; null means "use the shared description", which is what every existing
 * attachment keeps doing.
 *
 * Additive and nullable: no backfill, nothing changes on any site until a text
 * is written. Never auto-generated: copying or spinning the shared text would
 * be the same duplicate in different words.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('casino_site', 'description')) {
            return;
        }

        Schema::table('casino_site', function (Blueprint $table): void {
            $table->longText('description')->nullable()->after('active');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('casino_site', 'description')) {
            return;
        }

        Schema::table('casino_site', function (Blueprint $table): void {
            $table->dropColumn('description');
        });
    }
};
