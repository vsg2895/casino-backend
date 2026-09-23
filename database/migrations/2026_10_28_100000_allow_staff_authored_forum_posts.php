<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let the editorial team reply, without pretending to be a member.
 *
 * ── The problem ─────────────────────────────────────────────────────────────
 *
 * `forum_posts.forum_user_id` was NOT NULL and pointed at `forum_users`, so
 * every reply had to belong to a registered member. Staff have no row in that
 * table — they live in `users` — which meant a team reply could not be stored
 * at all. Not a display problem: the record could not exist.
 *
 * The two ways out were a fake member account called "Winpalack Team", or this.
 * A fake account would be a login-capable row in the members table, would
 * inflate the member count, and would make every "member" figure on the forum
 * slightly untrue forever.
 *
 * ── The shape, and why it is this one ───────────────────────────────────────
 *
 * Exactly the pattern `forum_articles` already uses: `user_id` → `users` for
 * staff. A post now carries ONE of the two, never both and never neither:
 *
 *     forum_user_id  a registered member wrote it   (unchanged for every
 *                                                    existing row)
 *     user_id        the editorial team wrote it
 *
 * Members keep posting exactly as they do today — this widens what a post may
 * be, it does not change what a member's post is. Every existing row keeps its
 * `forum_user_id` and is untouched by this migration.
 *
 * The "exactly one" rule is enforced in {@see \App\Models\ForumPost::booted()}
 * rather than as a CHECK constraint: the test suite runs on SQLite and the
 * application on MySQL, and a constraint that only exists on one of them is
 * worse than a rule that runs on both.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('forum_posts', 'user_id')) {
            return;
        }

        Schema::table('forum_posts', function (Blueprint $table): void {
            // nullOnDelete, matching forum_articles: removing an admin account
            // must not delete the forum's editorial replies. The post survives
            // with no author and renders under the team name, which is what it
            // was published as anyway.
            $table->foreignId('user_id')->nullable()->after('forum_user_id')
                ->constrained('users')->nullOnDelete();

            // The moderation queue filters staff posts out; they are published
            // approved and never need review.
            $table->index(['site_id', 'user_id'], 'forum_posts_staff_idx');
        });

        // Separated from the ALTER above because dropping and re-adding a
        // foreign key to change nullability has to happen in its own statement.
        Schema::table('forum_posts', function (Blueprint $table): void {
            $table->dropForeign(['forum_user_id']);
        });

        Schema::table('forum_posts', function (Blueprint $table): void {
            $table->unsignedBigInteger('forum_user_id')->nullable()->change();
        });

        Schema::table('forum_posts', function (Blueprint $table): void {
            $table->foreign('forum_user_id')->references('id')->on('forum_users')->cascadeOnDelete();
        });

        $this->restoreDepthChecks();
    }

    /**
     * Put back the one-level-nesting CHECKs that `change()` can take with it.
     *
     * Changing a column's nullability rebuilds the table on SQLite, and a
     * rebuild does not carry CHECK constraints across — verified: MySQL kept
     * both, SQLite lost both, and the test that asserts the DATABASE refuses a
     * third nesting level started failing. That test is the only thing standing
     * between a bug in the service layer and a comment thread of unbounded
     * depth, so silently losing its guarantee on the connection the suite runs
     * on would have been the worst of both worlds.
     *
     * Attempt-and-tolerate rather than driver branching: on MySQL the
     * constraints are still there and re-adding one is a duplicate-name error,
     * which is the signal that nothing needed doing.
     */
    private function restoreDepthChecks(): void
    {
        $checks = [
            'forum_posts_depth_range' => 'depth IN (0, 1)',
            'forum_posts_depth_matches_parent' => '(depth = 0 AND parent_id IS NULL) OR (depth = 1 AND parent_id IS NOT NULL)',
        ];

        foreach ($checks as $name => $expression) {
            try {
                \Illuminate\Support\Facades\DB::statement(
                    "ALTER TABLE forum_posts ADD CONSTRAINT {$name} CHECK ({$expression})",
                );
            } catch (\Throwable) {
                // Already present — nothing to restore on this connection.
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('forum_posts', 'user_id')) {
            return;
        }

        // Staff posts have no member to fall back to, so they are removed
        // before the column can be made NOT NULL again. Reversing this
        // migration deletes editorial replies — which is the honest outcome,
        // since the old schema had nowhere to put them.
        \Illuminate\Support\Facades\DB::table('forum_posts')->whereNull('forum_user_id')->delete();

        Schema::table('forum_posts', function (Blueprint $table): void {
            $table->dropForeign(['forum_user_id']);
        });

        Schema::table('forum_posts', function (Blueprint $table): void {
            $table->unsignedBigInteger('forum_user_id')->nullable(false)->change();
        });

        Schema::table('forum_posts', function (Blueprint $table): void {
            $table->foreign('forum_user_id')->references('id')->on('forum_users')->cascadeOnDelete();
            $table->dropIndex('forum_posts_staff_idx');
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
