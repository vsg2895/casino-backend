<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The verify-email send log.
 *
 * The double opt-in confirmation is the one stream with no history at all: it
 * is the FIRST email a subscriber ever gets, it decides whether they are
 * verified, and until now the only evidence it had been sent was an entry in
 * the application log — or the subscriber telling you it never arrived.
 *
 * Shaped exactly like `verification_promotion_sends`, down to the column names
 * and the index set, so the admin screen behind it is the same screen with a
 * different table and nothing new has to be learned to read it.
 *
 * ── Why the row survives the subscriber ─────────────────────────────────────
 *
 * `newsletter_id` NULLS rather than cascades. "We sent a verification to this
 * address on this day" stays true after the subscriber is deleted, and that is
 * exactly the period someone asks about. `email` is denormalised beside the FK
 * for the same reason: the address is the answer, so it cannot live only in a
 * row that may be gone.
 *
 * `site_id` DOES cascade: a deleted site's verification history describes a
 * brand that is no longer served from here at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verify_email_sends', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('newsletter_id')->nullable()->constrained()->nullOnDelete();

            $table->string('email', 255);

            // 'success' | 'failed' — the same vocabulary as every other send log.
            $table->string('status', 10);

            // Diagnostic head of a transport error. Null on success.
            $table->text('error')->nullable();

            /*
             * A send from the admin's "Send test" button rather than a real
             * subscriber's confirmation.
             *
             * It belongs in this log — an operator pressing test wants to see
             * it land here — but it is not evidence that any subscriber was
             * mailed, so the two must be distinguishable at a glance.
             */
            $table->boolean('is_test')->default(false);

            $table->timestamp('sent_at');
            $table->timestamp('created_at')->nullable();

            // The screen's default view: one site, newest first.
            $table->index(['site_id', 'sent_at'], 'ves_site_sent_idx');
            // "What happened to this address", and the failures-only filter.
            $table->index('email', 'ves_email_idx');
            $table->index(['status', 'sent_at'], 'ves_status_sent_idx');
            // The unfiltered listing, and the retention prune.
            $table->index(['sent_at', 'id'], 'ves_listing_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verify_email_sends');
    }
};
