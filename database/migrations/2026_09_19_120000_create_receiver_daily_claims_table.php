<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per receiver per day, whichever channel mailed them.
 *
 * The cross-channel duplicate guard. Each channel already has its own daily
 * unique index in its own history table, but those cannot see each other: with
 * only those, running a Mailgun credential and then an SMTP credential on the
 * same day mails the same person twice. This table is the shared claim both
 * senders must win before they send.
 *
 * The unique index is the guard, not a check-then-insert. A SELECT followed by
 * an INSERT races two workers against each other and both win; a unique
 * violation on insert cannot. This is the same technique the Mailgun history
 * table already uses, lifted to cover every channel.
 *
 * `channel` and `credential_id` are recorded for diagnosis only — nothing reads
 * them to make a decision. They exist so "why was this person skipped today?"
 * has an answer, and `credential_id` therefore carries no foreign key: it points
 * into a different table depending on the channel, and must survive the deletion
 * of the credential that wrote it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('receiver_daily_claims')) {
            return;
        }

        Schema::create('receiver_daily_claims', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('mailgun_receiver_id')
                ->constrained('mailgun_receivers')
                ->cascadeOnDelete();

            $table->date('claim_on');

            // 'mailgun' | 'smtp' — diagnostic only.
            $table->string('channel', 10);
            $table->unsignedBigInteger('credential_id');

            $table->timestamp('created_at')->nullable();

            // THE guard. Everything else in this table is commentary.
            $table->unique(['mailgun_receiver_id', 'claim_on'], 'receiver_daily_claims_unique');
            // Lets an operator sweep a day's claims when a run has to be redone.
            $table->index(['claim_on', 'channel'], 'receiver_daily_claims_day_channel_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receiver_daily_claims');
    }
};
