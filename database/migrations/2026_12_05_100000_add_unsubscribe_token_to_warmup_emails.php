<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gives every warmup address its own opt-out, like a subscriber and like a
 * Custom Receivers address.
 *
 * Until now the warmup list was the one audience whose messages carried an
 * INERT unsubscribe link: warmup exists to build a sending mailbox's
 * reputation, the addresses are seed mailboxes, and the original design took
 * the view that a seed never unsubscribes. That held while warmup only ever
 * received warmup traffic. It stopped holding when a schedule could aim a real
 * site template at the warmup list, because the link in that message is read by
 * mailbox providers and, sometimes, by a person.
 *
 * `unsubscribe_token` is NULLABLE so the column can be added to a populated
 * table without inventing values inside a migration, and UNIQUE so two rows can
 * never share a credential. MySQL permits many NULLs under a unique index,
 * which is exactly the "not yet backfilled" state
 * {@see \Database\Seeders\ReceiverUnsubscribeTokenSeeder} fills — run it right
 * after this migration. Every row created from then on gets a token from the
 * model's `creating` hook.
 *
 * `unsubscribed_at` is what makes the link honest: without it the endpoint
 * would have nowhere to record the decision, and the address would keep
 * receiving. It mirrors the column of the same name on the receiver lists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warmup_emails', function (Blueprint $table): void {
            $table->string('unsubscribe_token', 64)->nullable()->unique()->after('email');
            $table->timestamp('unsubscribed_at')->nullable()->after('last_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('warmup_emails', function (Blueprint $table): void {
            // The unique index is named by convention and must go before the
            // column it covers, or MySQL refuses the drop.
            $table->dropUnique('warmup_emails_unsubscribe_token_unique');
            $table->dropColumn(['unsubscribe_token', 'unsubscribed_at']);
        });
    }
};
