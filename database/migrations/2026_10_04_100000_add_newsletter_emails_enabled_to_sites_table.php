<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a site actually MAILS the people who subscribe to it.
 *
 * Off, the signup form still works and the address is still recorded — only the
 * outbound mail stops. That combination is the point: a site can keep collecting
 * an audience while its sending domain is being warmed, while a template is
 * being rewritten, or while deliverability is being investigated, without taking
 * the form down and losing the signups.
 *
 * Defaults TRUE, so no existing site changes behaviour by this migration
 * running. Turning it off is an explicit act in the admin panel.
 *
 * SUBSCRIBERS TAKEN WHILE THIS IS OFF STAY UNVERIFIED. They have not confirmed
 * anything — no email was sent, so there was no link to click — and stamping
 * them verified would fabricate a consent record. They sit pending, and if the
 * switch is turned back on they can be sent the verify email then.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sites') && ! Schema::hasColumn('sites', 'newsletter_emails_enabled')) {
            Schema::table('sites', function (Blueprint $table): void {
                $table->boolean('newsletter_emails_enabled')->default(true);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sites') && Schema::hasColumn('sites', 'newsletter_emails_enabled')) {
            Schema::table('sites', function (Blueprint $table): void {
                $table->dropColumn('newsletter_emails_enabled');
            });
        }
    }
};
