<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-site switch for the operator profile block.
 *
 * Follows `countries_enabled` and `reviews_enabled` exactly: default FALSE, so a
 * new surface never switches itself on for a live domain. It is turned on for
 * winpalack from the admin, and the other five sites keep rendering exactly what
 * they render today until someone decides otherwise.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sites') || Schema::hasColumn('sites', 'operator_profile_enabled')) {
            return;
        }

        Schema::table('sites', function (Blueprint $table): void {
            $table->boolean('operator_profile_enabled')->default(false)->after('reviews_enabled');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('sites') && Schema::hasColumn('sites', 'operator_profile_enabled')) {
            Schema::table('sites', function (Blueprint $table): void {
                $table->dropColumn('operator_profile_enabled');
            });
        }
    }
};
