<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The authored fields behind a credential's receiver message.
 *
 * `message_html` (added by 2026_09_13_160000) stays exactly where it is and
 * keeps its meaning: it is what the send path reads. What changes is who writes
 * it — the admin now fills in template fields instead of pasting raw HTML, and
 * the backend renders those fields into `message_html` on save.
 *
 * Storing the fields as ONE json column rather than a dozen columns:
 *  - they are edited and saved as a single atomic form, never individually;
 *  - nothing filters, sorts or joins on any of them, so no column would ever be
 *    indexed and none needs to be a first-class column;
 *  - `mailgun_keys` is primarily a credentials table, and a dozen presentation
 *    columns on it would make the row's purpose harder to read.
 * SitePromotionEmail keeps discrete columns for the opposite reason: it IS a
 * template table, and its fields are queried and defaulted per site.
 *
 * Nullable with no default, so every existing credential is untouched: a row
 * with no template renders from {@see MailgunReceiverTemplate::defaults()}, and
 * one whose `message_html` was authored by hand before this migration keeps
 * sending that HTML until the admin saves the modal again.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mailgun_keys')) {
            return;
        }

        Schema::table('mailgun_keys', function (Blueprint $table): void {
            if (! Schema::hasColumn('mailgun_keys', 'message_template')) {
                $table->json('message_template')->nullable()->after('message_html');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('mailgun_keys')) {
            return;
        }

        // Drops only the column this migration added. `message_html` survives, so
        // a rollback costs the editable fields but never the message that is
        // actually being sent.
        Schema::table('mailgun_keys', function (Blueprint $table): void {
            if (Schema::hasColumn('mailgun_keys', 'message_template')) {
                $table->dropColumn('message_template');
            }
        });
    }
};
