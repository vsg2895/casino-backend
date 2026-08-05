<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a schedule deliver through a stored Mailgun credential.
 *
 * Purely additive and fully backward compatible:
 *  - `provider` is already a free-form string column, so accepting the new
 *    'mailgun' value needs no schema change and no enum migration.
 *  - `mailgun_key_id` is nullable, so every existing row (smtp / sendgrid)
 *    stays valid untouched and keeps working exactly as before.
 *  - nullOnDelete mirrors sendgrid_key_id: if the credential is removed the
 *    schedule survives but fails gracefully (logged) until a valid one is
 *    chosen, rather than cascading the schedule away.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_schedules', function (Blueprint $table): void {
            $table->foreignId('mailgun_key_id')
                ->nullable()
                ->after('sendgrid_key_id')
                ->constrained('mailgun_keys')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('email_schedules', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('mailgun_key_id');
        });
    }
};
