<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-site switch: hide casino and offer pages that carry no description of
 * this site's own from search and from the sitemap.
 *
 * Defaults to false and is switched on by hand in the admin — never here.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('sites', 'noindex_without_override')) {
            return;
        }

        Schema::table('sites', function (Blueprint $table): void {
            $table->boolean('noindex_without_override')->default(false)->after('bonus_enabled');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('sites', 'noindex_without_override')) {
            return;
        }

        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn('noindex_without_override');
        });
    }
};
