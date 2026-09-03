<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-recipient send history for the Mailgun receiver pipeline.
 *
 * Separate from `promotion_email_history` on purpose: that table is partitioned,
 * keyed to sites and schedules, and owned by the promotion workflow, which this
 * change must not touch.
 *
 * `email` is denormalised alongside `mailgun_receiver_id` for the same reason
 * the promotion history does it: a receiver may later be deleted, and the audit
 * trail of what was sent has to survive that.
 *
 * The unique index is the duplicate guard. `(mailgun_key_id, mailgun_receiver_id,
 * sent_on)` means one credential can record at most ONE send per receiver per
 * calendar day, so a retried job or two concurrent workers cannot double-send:
 * the second insert violates the constraint and is skipped rather than mailing
 * the person twice. `sent_on` is a stored DATE, not a timestamp, precisely so
 * the constraint has day granularity.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mailgun_receiver_sends')) {
            return;
        }

        Schema::create('mailgun_receiver_sends', function (Blueprint $table): void {
            $table->id();

            // Which credential sent it. Cascades: history for a deleted
            // credential has no meaning, and leaving orphans would break the
            // "sent by this credential" filter the UI offers.
            $table->foreignId('mailgun_key_id')
                ->constrained('mailgun_keys')
                ->cascadeOnDelete();

            // Nulled rather than cascaded: the receiver may go, the record stays.
            $table->foreignId('mailgun_receiver_id')
                ->nullable()
                ->constrained('mailgun_receivers')
                ->nullOnDelete();

            $table->string('email', 255);

            // 'sent' | 'failed' | 'skipped'
            $table->string('status', 10)->index();
            $table->text('error')->nullable();

            $table->timestamp('sent_at');
            // Day-granular duplicate guard — see the class docblock.
            $table->date('sent_on');

            $table->timestamps();

            $table->unique(
                ['mailgun_key_id', 'mailgun_receiver_id', 'sent_on'],
                'mailgun_receiver_sends_daily_unique',
            );
            // Backs the per-credential history listing and its count endpoint.
            $table->index(['mailgun_key_id', 'sent_at'], 'mailgun_receiver_sends_key_sent_index');
            $table->index('email', 'mailgun_receiver_sends_email_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mailgun_receiver_sends');
    }
};
