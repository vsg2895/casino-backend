<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-attempt history for the post-verification promotion stream.
 *
 * ── Why a table of its own, when these sends are already recorded ───────────
 *
 * {@see \App\Jobs\SendVerificationPromotionJob} writes every outcome into the
 * shared `promotion_email_histories`, and must keep doing so: that table is
 * what the 24-hour dedup reads, so a post-verification email and a campaign
 * email cannot both reach the same address on the same day. It is load-bearing
 * and is not being changed here.
 *
 * What it cannot do is answer "show me the post-verification sends". It has no
 * stream column, so those rows are indistinguishable from campaign rows; and
 * its unique key is (site_id, email, sent_date, status), so when both streams
 * reach one address on one day they MERGE into a single row. Adding a column to
 * it would not fix the merge, and it is monthly-partitioned and retained
 * forever — the wrong table to rebuild for a reporting need.
 *
 * So this is the stream's own log, shaped after `warmup_send_recipients`, which
 * is the pattern the admin's Warmup History screen already reads: one row per
 * attempt, carrying the address, the site, the outcome and when it happened.
 *
 * ── Immutable, like its sibling ─────────────────────────────────────────────
 *
 * An attempt happened or it did not; the row is never revised, so there is no
 * `updated_at`. `email` is denormalised beside `newsletter_id` on purpose: the
 * subscriber may later be deleted, and the history of what the platform sent
 * must stay readable when they are — the FK is therefore nullOnDelete rather
 * than cascade.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('verification_promotion_sends')) {
            return;
        }

        Schema::create('verification_promotion_sends', function (Blueprint $table): void {
            $table->id();

            // The site whose template was rendered. Cascade: a deleted site's
            // history is about a brand that no longer exists here.
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();

            // Who it went to. Nulled rather than cascaded — see the class note.
            $table->foreignId('newsletter_id')->nullable()->constrained()->nullOnDelete();

            $table->string('email', 255);

            // 'success' | 'failed', matching PromotionEmailHistory's vocabulary
            // so one word means one thing across the messaging subsystem.
            $table->string('status', 10);

            // Diagnostic head of a transport error. Nullable, and null on success.
            $table->text('error')->nullable();

            $table->timestamp('sent_at');
            $table->timestamp('created_at')->nullable();

            // The screen's default view: one site, newest first.
            $table->index(['site_id', 'sent_at'], 'vps_site_sent_idx');
            // "What happened to this address", and the failures-only filter.
            $table->index('email', 'vps_email_idx');
            $table->index(['status', 'sent_at'], 'vps_status_sent_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_promotion_sends');
    }
};
