<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Forum members — the platform's first visitor accounts.
 *
 * ── Why not the `users` table ───────────────────────────────────────────────
 *
 * `users` is the ADMIN table. It carries Spatie roles, it is what Sanctum
 * authenticates for the Vue panel, and it holds one row. Registering visitors
 * into it would put strangers in the same table as `super-admin`, one
 * mis-scoped policy away from the admin API — and it would make the panel's
 * user list unusable the first week the forum works.
 *
 * The two also disagree on behaviour that is already shipped: AuthController's
 * changePassword deliberately revokes EVERY token a user holds, which is
 * correct for an operator and hostile to a member reading a thread on a phone.
 *
 * ── Per-site accounts ───────────────────────────────────────────────────────
 *
 * `site_id` is part of both unique keys, so the same person may register the
 * same address on two domains and they are two accounts. That is the decision
 * taken for this build: the six sites are separate brands on separate cookie
 * domains, and network-wide SSO is a project rather than a column.
 *
 * Nothing here forecloses it. If accounts ever go network-wide, `site_id`
 * becomes "where they signed up" and the unique keys drop to (email) — a
 * migration, not a redesign.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('forum_users')) {
            return;
        }

        Schema::create('forum_users', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();

            $table->string('display_name', 60);
            // In the URL of the member's public profile, so it is generated once
            // and never regenerated on rename — the same rule the rest of the
            // platform's slugs follow.
            $table->string('slug', 80);

            $table->string('email', 255);
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();

            $table->string('avatar_path', 255)->nullable();

            /*
             * Trust, and the two counters that decide it.
             *
             * `approved_posts_count` is SEPARATE from `posts_count` and it is the
             * one pre-moderation reads. A spammer whose every post is rejected
             * would otherwise graduate out of the moderation queue purely by
             * volume — posting 3 times is not the bar, having 3 posts accepted
             * is. Link posting keys off the same number.
             */
            $table->unsignedTinyInteger('trust_level')->default(0);
            $table->unsignedInteger('posts_count')->default(0);
            $table->unsignedInteger('approved_posts_count')->default(0);

            // active / muted / banned. A string rather than a MySQL ENUM: the
            // platform stores `status` as a short varchar on casino_reviews too,
            // and adding a value to an ENUM is a table rebuild.
            $table->string('status', 12)->default('active');
            // Set for a temporary mute; null on a permanent ban, which is why
            // `status` cannot be inferred from this column alone.
            $table->timestamp('banned_until')->nullable();
            $table->string('ban_reason', 255)->nullable();

            // Drives the "online now" figure on the forum index. Written at most
            // once a minute per member — see ForumPresence.
            $table->timestamp('last_seen_at')->nullable();
            // Kept for abuse forensics only, never exposed by any resource.
            $table->binary('registration_ip', 16)->nullable();

            $table->timestamps();
            $table->softDeletes();

            // One account per address per site. Enforced in the schema because a
            // duplicate here is an account-takeover vector, not a tidiness issue.
            $table->unique(['site_id', 'email']);
            $table->unique(['site_id', 'slug']);

            // The admin's member list: this site, filtered by state, newest
            // first. `id` closes the sort so the index answers the ORDER BY too.
            $table->index(['site_id', 'status', 'id'], 'forum_users_admin_idx');
            // "Online now": one bounded range scan over a recent window.
            $table->index(['site_id', 'last_seen_at'], 'forum_users_presence_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forum_users');
    }
};
