<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per SMS send ATTEMPT — the per-recipient result the bulk send records.
 *
 * The brief's requirement is "record the result for each phone number", and this
 * is that record: what was sent, through which credential, whether Twilio
 * accepted it, and the exact error when it did not. A batch never aborts on a
 * single bad number, so this table is the only place a partial failure is
 * visible after the fact.
 *
 * `phone` is stored as a plain string rather than a foreign key to
 * `newsletters_based_on_phone`. That is deliberate: history must survive the
 * number being deleted from the list (the audit is about what was sent, not
 * about who is currently subscribed), and it keeps the feature free of the
 * relationships the brief rules out. It mirrors how `promotion_email_histories`
 * stores an address rather than a newsletter_id.
 *
 * `twilio_config_id` is a nullable FK with nullOnDelete: deleting a credential
 * must not erase the record of what it sent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phone_sms_histories', function (Blueprint $table): void {
            $table->id();

            $table->string('phone', 20);

            $table->foreignId('twilio_config_id')->nullable()
                ->constrained('twilio_configs')->nullOnDelete();

            // Twilio's message identifier ("SM…"), when the API accepted it.
            // The handle for looking a delivery up in the Twilio console later.
            $table->string('message_sid', 64)->nullable();

            $table->string('status', 12); // sent|failed
            // Twilio's numeric code (e.g. 21610 opted out, 21614 not mobile),
            // kept separately from the message so failures can be counted by
            // cause without parsing prose.
            $table->unsignedInteger('error_code')->nullable();
            $table->text('error')->nullable();

            // The message body as delivered. Short by nature (SMS), and keeping
            // it is what makes the history answerable: "what did we send them?"
            $table->text('body')->nullable();

            $table->timestamps();

            // The listing's default order, and the lookup used to check what a
            // given number has already received.
            $table->index(['phone', 'created_at']);
            $table->index('created_at');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_sms_histories');
    }
};
