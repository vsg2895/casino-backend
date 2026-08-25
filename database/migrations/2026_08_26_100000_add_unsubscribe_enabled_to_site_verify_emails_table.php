<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets the verify email's footer unsubscribe LINK be removed and restored.
 *
 * A flag rather than clearing `unsubscribe_label`, which is how every other
 * removable block in this template works. Clearing is destructive: the wording is
 * gone and "restore" could only ever put back a default, not what the operator
 * actually had. A boolean keeps the label row untouched, so restoring re-renders
 * the exact same link.
 *
 * SCOPE: this hides one block in the rendered body. It does NOT touch the
 * unsubscribe process — tokens, `Unsubscribe::oneClickUrl()`, the
 * `List-Unsubscribe` / `List-Unsubscribe-Post` headers and the public
 * `POST /api/v1/unsubscribe/{token}` route are all unchanged, and a message with
 * the link hidden still carries the RFC 8058 headers.
 *
 * Defaults to TRUE so every existing row keeps rendering exactly as it does today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_verify_emails', function (Blueprint $table): void {
            $table->boolean('unsubscribe_enabled')
                ->default(true)
                ->after('unsubscribe_label');
        });
    }

    public function down(): void
    {
        Schema::table('site_verify_emails', function (Blueprint $table): void {
            $table->dropColumn('unsubscribe_enabled');
        });
    }
};
