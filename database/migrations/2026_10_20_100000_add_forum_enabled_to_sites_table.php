<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The community forum's per-site switch.
 *
 * DISTINCT from `site_forums.enabled`, which despite the name has never gated a
 * forum: it gates the combined *review feed*, the page that just moved from
 * /forum to /reviews. The two features share nothing but a word.
 *
 * Off by default, as every feature flag on this table is. The public routes
 * 404 while it is false — the same treatment countries and guides get — so a
 * site that has not opted in never advertises a dead section.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('sites', 'forum_enabled')) {
            return;
        }

        Schema::table('sites', function (Blueprint $table): void {
            $table->boolean('forum_enabled')->default(false)->after('bonus_enabled');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('sites', 'forum_enabled')) {
            return;
        }

        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn('forum_enabled');
        });
    }
};
