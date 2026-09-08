<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Named authorship and a real review date.
 *
 * THE AUTHOR LIVES ON THE SITE, not in an `authors` table. The roadmap's own
 * instruction, and the right call while one person writes everything: a table
 * plus its CRUD plus a relation is three moving parts to express "this site's
 * reviews are by this person". Normalising it is a migration on the day a second
 * author exists, and not before.
 *
 * `casinos.reviewed_at` is deliberately SEPARATE from `updated_at`. `updated_at`
 * bumps when anyone changes any field — a typo fix, a new image — so presenting
 * it as "last checked" overstates what happened. `reviewed_at` is set only when
 * someone actually re-checks the operator, which is the claim a reader is being
 * asked to trust.
 *
 * `byline_enabled` defaults FALSE. That is not caution about rollout: a byline
 * asserts a person stands behind the review, and the wrong default would have
 * six sites making that claim the moment this migration ran.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sites')) {
            Schema::table('sites', function (Blueprint $table): void {
                if (! Schema::hasColumn('sites', 'author_name')) {
                    $table->string('author_name', 120)->nullable();
                }
                if (! Schema::hasColumn('sites', 'author_role')) {
                    $table->string('author_role', 120)->nullable();
                }
                if (! Schema::hasColumn('sites', 'author_bio')) {
                    $table->text('author_bio')->nullable();
                }
                if (! Schema::hasColumn('sites', 'author_avatar_path')) {
                    $table->string('author_avatar_path', 500)->nullable();
                }
                if (! Schema::hasColumn('sites', 'methodology_page_slug')) {
                    // Points at one of the site's own CMS pages. A slug rather
                    // than a foreign key because CMS pages are already addressed
                    // by slug everywhere else on the public side.
                    $table->string('methodology_page_slug', 120)->nullable();
                }
                if (! Schema::hasColumn('sites', 'byline_enabled')) {
                    $table->boolean('byline_enabled')->default(false)->after('operator_profile_enabled');
                }
            });
        }

        if (Schema::hasTable('casinos') && ! Schema::hasColumn('casinos', 'reviewed_at')) {
            Schema::table('casinos', function (Blueprint $table): void {
                // DATE, not datetime: "checked on 4 September" is the claim, and
                // a timestamp would imply a precision nobody recorded.
                $table->date('reviewed_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sites')) {
            Schema::table('sites', function (Blueprint $table): void {
                foreach (['author_name', 'author_role', 'author_bio', 'author_avatar_path', 'methodology_page_slug', 'byline_enabled'] as $column) {
                    if (Schema::hasColumn('sites', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('casinos') && Schema::hasColumn('casinos', 'reviewed_at')) {
            Schema::table('casinos', function (Blueprint $table): void {
                $table->dropColumn('reviewed_at');
            });
        }
    }
};
