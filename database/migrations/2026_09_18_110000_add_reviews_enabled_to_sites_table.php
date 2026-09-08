<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-site switch for visitor reviews.
 *
 * Modelled exactly like `countries_enabled`: a dedicated boolean, DEFAULT FALSE,
 * so deploying the feature does not open a public write endpoint on six live
 * domains. Each site is opted in deliberately from the admin.
 *
 * The switch gates BOTH directions — reading reviews and writing them — because
 * a site that does not display reviews has no reason to accept them either.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sites') || Schema::hasColumn('sites', 'reviews_enabled')) {
            return;
        }

        Schema::table('sites', function (Blueprint $table): void {
            $table->boolean('reviews_enabled')->default(false)->after('countries_enabled');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('sites') || ! Schema::hasColumn('sites', 'reviews_enabled')) {
            return;
        }

        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn('reviews_enabled');
        });
    }
};
