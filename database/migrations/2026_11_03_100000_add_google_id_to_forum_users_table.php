<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links a member account to a Google identity.
 *
 * `sub` from the ID token, which is Google's stable, per-account identifier. The
 * EMAIL is not that identifier: a person can change the address on their Google
 * account, and matching on it alone would hand the renamed address to whoever
 * registers it next. Email is still how an existing password account is found
 * the first time somebody signs in with Google — after that the link is this
 * column.
 *
 * Unique PER SITE, like every other key on this table: the same person may hold
 * an account on two of the six domains, and those are different accounts.
 * Nullable because almost every row will never use Google at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('forum_users') || Schema::hasColumn('forum_users', 'google_id')) {
            return;
        }

        Schema::table('forum_users', function (Blueprint $table): void {
            // Google documents `sub` as at most 255 characters; today it is a
            // 21-digit number. 64 is room enough without indexing a long string.
            $table->string('google_id', 64)->nullable()->after('email_verified_at');
            $table->unique(['site_id', 'google_id'], 'forum_users_site_google_unique');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('forum_users') || ! Schema::hasColumn('forum_users', 'google_id')) {
            return;
        }

        Schema::table('forum_users', function (Blueprint $table): void {
            $table->dropUnique('forum_users_site_google_unique');
            $table->dropColumn('google_id');
        });
    }
};
