<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What KIND of SMTP server a credential points at.
 *
 * Not cosmetic, and not derivable from the other columns. Mailgun's SMTP
 * gateway takes an SMTP password that looks exactly like an API key and happens
 * to run on 587/STARTTLS, while an own mailbox runs on 465/SSL — but host and
 * port are free-form, so inferring the type from them would be a guess that
 * quietly becomes wrong the first time someone runs their own server on 587.
 *
 * The operator knows which it is; the column records it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('smtp_credentials') || Schema::hasColumn('smtp_credentials', 'type')) {
            return;
        }

        Schema::table('smtp_credentials', function (Blueprint $table): void {
            // 'own_smtp' | 'mailgun_smtp'. Defaults to own_smtp because that is
            // the plain case — a Mailgun gateway is the one you opt into.
            $table->string('type', 20)->default('own_smtp')->after('name');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('smtp_credentials') && Schema::hasColumn('smtp_credentials', 'type')) {
            Schema::table('smtp_credentials', function (Blueprint $table): void {
                $table->dropColumn('type');
            });
        }
    }
};
