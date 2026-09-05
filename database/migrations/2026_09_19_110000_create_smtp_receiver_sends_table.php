<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-address send history for SMTP campaigns — the twin of
 * `mailgun_receiver_sends`.
 *
 * A separate table rather than a nullable credential column on the Mailgun one.
 * That table's daily unique index is (mailgun_key_id, mailgun_receiver_id,
 * sent_on); making the key nullable would silently disable that guard for every
 * SMTP row, because MySQL treats NULLs in a unique index as distinct. Two
 * narrow tables keep each channel's own guard intact.
 *
 * Cross-channel "one email per person per day" is NOT this index's job — it is
 * enforced for both channels by `receiver_daily_claims`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('smtp_receiver_sends')) {
            return;
        }

        Schema::create('smtp_receiver_sends', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('smtp_credential_id')
                ->constrained('smtp_credentials')
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
            $table->date('sent_on');

            $table->timestamps();

            $table->unique(
                ['smtp_credential_id', 'mailgun_receiver_id', 'sent_on'],
                'smtp_receiver_sends_daily_unique',
            );
            $table->index(['smtp_credential_id', 'sent_at'], 'smtp_receiver_sends_credential_sent_index');
            $table->index('email', 'smtp_receiver_sends_email_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smtp_receiver_sends');
    }
};
