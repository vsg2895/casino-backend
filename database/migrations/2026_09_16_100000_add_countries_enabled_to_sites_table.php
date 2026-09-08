<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-site switch for the countries filter.
 *
 * A dedicated column rather than a key in the existing `settings` json bag:
 * the flag gates a public endpoint and will be filtered on
 * (`Site::where('countries_enabled', true)`), and a load-bearing value has no
 * business living in an untyped blob that currently holds null on every row.
 * It is modelled exactly like `active`, which it most resembles.
 *
 * DEFAULT FALSE. Adding a feature must not switch it on for six live domains
 * the moment this deploys — each site is opted in deliberately from the admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sites') || Schema::hasColumn('sites', 'countries_enabled')) {
            return;
        }

        Schema::table('sites', function (Blueprint $table): void {
            $table->boolean('countries_enabled')->default(false)->after('active');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('sites') || ! Schema::hasColumn('sites', 'countries_enabled')) {
            return;
        }

        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn('countries_enabled');
        });
    }
};
