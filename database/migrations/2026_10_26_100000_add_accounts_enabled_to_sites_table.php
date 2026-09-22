<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Member accounts, separated from the discussion board.
 *
 * `forum_enabled` has been doing two jobs: it opens the board at /forum AND it
 * is the only thing standing between a visitor and /login, /register and the
 * header's account control. A site that wants sign-ups before its board is
 * ready — collecting members first, opening discussions later — has had no way
 * to express that, and turning the board on to get a sign-in button publishes a
 * section with nothing in it.
 *
 * So accounts get their own switch. `forum_enabled` keeps meaning "the board is
 * open"; this one means "people may have an account here".
 *
 * They are not fully independent in one direction, and deliberately so: an open
 * board with accounts off would render a reply box nobody can ever use. That is
 * resolved in {@see \App\Models\Site::allowsAccounts()} — the board IMPLIES
 * accounts — rather than by a constraint here, so an operator cannot create the
 * broken combination by toggling in the wrong order.
 *
 * Backfilled from `forum_enabled`, so every existing site keeps exactly the
 * behaviour it has today and this migration changes nothing on its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('sites', 'accounts_enabled')) {
            return;
        }

        Schema::table('sites', function (Blueprint $table): void {
            // Off by default, like every other per-site feature flag: a new
            // domain opts in rather than inheriting a member area.
            $table->boolean('accounts_enabled')->default(false)->after('forum_enabled');
        });

        // Every site that already has a board already has accounts. Copying the
        // value is what makes this migration a no-op behaviourally.
        DB::table('sites')->where('forum_enabled', true)->update(['accounts_enabled' => true]);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('sites', 'accounts_enabled')) {
            return;
        }

        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn('accounts_enabled');
        });
    }
};
