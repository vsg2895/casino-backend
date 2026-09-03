<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The message each credential sends to its receivers.
 *
 * Per credential rather than global, because the brief makes the credential the
 * unit of configuration: its own receivers, its own rule, its own message.
 *
 * Both columns are nullable with no default — a credential that has never been
 * configured has no message, and the campaign dispatcher refuses to run it
 * rather than mailing an empty body.
 *
 * Note what is NOT stored here: the unsubscribe link and the List-Unsubscribe
 * headers are appended by MailgunReceiverMessage at render time, not authored in
 * this template, so they cannot be edited away.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mailgun_keys')) {
            return;
        }

        Schema::table('mailgun_keys', function (Blueprint $table): void {
            if (! Schema::hasColumn('mailgun_keys', 'message_subject')) {
                $table->string('message_subject', 255)->nullable()->after('last_run_at');
            }

            if (! Schema::hasColumn('mailgun_keys', 'message_html')) {
                $table->longText('message_html')->nullable()->after('message_subject');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('mailgun_keys')) {
            return;
        }

        Schema::table('mailgun_keys', function (Blueprint $table): void {
            foreach (['message_html', 'message_subject'] as $column) {
                if (Schema::hasColumn('mailgun_keys', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
