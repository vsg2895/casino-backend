<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Global (default-site) meta title and description for special offers.
 *
 * Nullable and not backfilled: an empty value keeps every page exactly as it
 * renders today, and the public API derives a fallback where it needs one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('special_offers', function (Blueprint $table): void {
            if (! Schema::hasColumn('special_offers', 'meta_title')) {
                $table->string('meta_title', 255)->nullable()->after('description');
            }

            if (! Schema::hasColumn('special_offers', 'meta_description')) {
                $table->string('meta_description', 500)->nullable()->after('meta_title');
            }
        });
    }

    public function down(): void
    {
        Schema::table('special_offers', function (Blueprint $table): void {
            foreach (['meta_description', 'meta_title'] as $column) {
                if (Schema::hasColumn('special_offers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
