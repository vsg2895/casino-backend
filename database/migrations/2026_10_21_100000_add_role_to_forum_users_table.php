<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A role on the forum member.
 *
 * ── Why this is NOT Spatie, which the platform already has ──────────────────
 *
 * `spatie/laravel-permission` is installed and the ADMIN users use it. It is
 * deliberately not extended to forum members: its tables key on a model_type
 * plus a guard, and mixing visitor roles into the same `model_has_roles` table
 * that grants `super-admin` puts the two populations one mis-scoped query apart.
 * The forum needs exactly one axis with a handful of values, so it is a column.
 *
 * ── Role is not status ──────────────────────────────────────────────────────
 *
 * `status` (active / muted / banned) is a MODERATION state that changes often.
 * `role` is what the account IS, and changes almost never. Collapsing them would
 * mean a banned moderator loses their role on being unbanned, or that promoting
 * someone silently un-mutes them.
 *
 * Everyone registers as `user`. Nothing in the public API can set this column —
 * it is absent from `$fillable`, so no mass-assignment can grant a role.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('forum_users', 'role')) {
            return;
        }

        Schema::table('forum_users', function (Blueprint $table): void {
            $table->string('role', 20)->default('user')->after('status');
            // The admin list filters on it, alongside the site and the status.
            $table->index(['site_id', 'role'], 'forum_users_role_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('forum_users', 'role')) {
            return;
        }

        Schema::table('forum_users', function (Blueprint $table): void {
            $table->dropIndex('forum_users_role_idx');
            $table->dropColumn('role');
        });
    }
};
