<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets each schedule choose HOW its promotion campaign is delivered:
 *  - `provider` = 'smtp'     → the existing .env SMTP transport (default, so
 *                              every pre-existing schedule keeps working unchanged).
 *  - `provider` = 'sendgrid' → the native SendGrid Web API, authenticated with a
 *                              stored key referenced by `sendgrid_key_id`.
 *
 * `sendgrid_key_id` is nullOnDelete: if the chosen key is removed, the schedule
 * survives but the send fails gracefully (logged) until a valid key is picked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_schedules', function (Blueprint $table): void {
            $table->string('provider', 20)->default('smtp')->after('active');
            $table->foreignId('sendgrid_key_id')
                ->nullable()
                ->after('provider')
                ->constrained('sendgrid_keys')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('email_schedules', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('sendgrid_key_id');
            $table->dropColumn('provider');
        });
    }
};
