<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Guarded so it is a no-op on fresh installs (the create-sites migration
        // no longer adds the column) and drops it on already-migrated databases.
        if (Schema::hasColumn('sites', 'currency')) {
            Schema::table('sites', function (Blueprint $table): void {
                $table->dropColumn('currency');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('sites', 'currency')) {
            Schema::table('sites', function (Blueprint $table): void {
                $table->char('currency', 3)->default('USD');
            });
        }
    }
};
