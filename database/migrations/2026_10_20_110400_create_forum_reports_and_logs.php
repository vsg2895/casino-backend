<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Visitor reports, and the moderation audit trail.
 *
 * Reports are the cheapest moderation signal a forum has: readers find spam
 * faster than any schedule does. The log exists because a moderation action
 * that cannot be attributed is not a moderation system — every approve, reject,
 * spam-mark, ban and restore records who did it and when.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('forum_reports')) {
            Schema::create('forum_reports', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('site_id')->constrained()->cascadeOnDelete();
                $table->foreignId('forum_post_id')->constrained('forum_posts')->cascadeOnDelete();
                // Nullable so a guest can report without an account — reporting
                // is the one write a forum should never gate behind signing up.
                $table->foreignId('forum_user_id')->nullable()->constrained('forum_users')->nullOnDelete();
                $table->binary('reporter_ip', 16)->nullable();

                // spam / abuse / off_topic / other.
                $table->string('reason', 20);
                $table->string('note', 500)->nullable();

                // open / actioned / dismissed.
                $table->string('status', 12)->default('open');
                $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('handled_at')->nullable();

                $table->timestamps();

                // The queue: this site's open reports, newest first.
                $table->index(['site_id', 'status', 'id'], 'forum_reports_queue_idx');
                // How many distinct reports one post has attracted.
                $table->index(['forum_post_id', 'status'], 'forum_reports_post_idx');
                /*
                 * One report per member per post.
                 *
                 * Only constrains SIGNED-IN reporters: MySQL treats NULLs as
                 * distinct in a unique index, so guests are not collapsed into a
                 * single row by this key. Guest flooding is handled by the rate
                 * limiter instead, which is the right tool — a unique key on a
                 * shared IP would silence a whole office.
                 */
                $table->unique(['forum_post_id', 'forum_user_id'], 'forum_reports_once_per_member');
            });
        }

        if (Schema::hasTable('forum_moderation_logs')) {
            return;
        }

        Schema::create('forum_moderation_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            // The acting admin. nullOnDelete rather than cascade: the record of
            // what was done must outlive the account that did it.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // Morph columns rather than a FK, because the subject may be a post,
            // an article or a member — and because the log must survive the
            // subject being hard-deleted, which a foreign key would prevent.
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');

            $table->string('action', 30);
            $table->string('reason', 500)->nullable();
            // Enough to answer "what did this look like before", without a
            // second table for one column.
            $table->string('from_status', 12)->nullable();
            $table->string('to_status', 12)->nullable();

            // Append-only: there is no updated_at because a log entry is never
            // edited.
            $table->timestamp('created_at')->nullable();

            $table->index(['subject_type', 'subject_id', 'id'], 'forum_logs_subject_idx');
            $table->index(['site_id', 'id'], 'forum_logs_site_idx');
            $table->index(['user_id', 'id'], 'forum_logs_actor_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forum_moderation_logs');
        Schema::dropIfExists('forum_reports');
    }
};
