<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The analytics service a site runs, by name ("Google Analytics"), or null.
 *
 * The legal pages are one template for every site, and only some sites load an
 * analytics tag. The Privacy and Cookie policies used to name Google Analytics
 * on all of them, which was false wherever no tag runs. Their analytics lines
 * now carry `data-requires="analytics_provider"` and print this name, so they
 * appear exactly where it is set (see App\Support\LegalIdentity).
 *
 * Nullable with no default: nothing may be guessed, and a site that sets
 * nothing publishes no analytics claim.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sites') || Schema::hasColumn('sites', 'analytics_provider')) {
            return;
        }

        Schema::table('sites', function (Blueprint $table): void {
            $after = Schema::hasColumn('sites', 'privacy_email') ? 'privacy_email' : null;
            $column = $table->string('analytics_provider', 120)->nullable();

            if ($after !== null) {
                $column->after($after);
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('sites', 'analytics_provider')) {
            Schema::table('sites', function (Blueprint $table): void {
                $table->dropColumn('analytics_provider');
            });
        }
    }
};
