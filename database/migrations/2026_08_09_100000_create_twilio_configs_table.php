<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stored Twilio credentials used to authenticate bulk SMS sends.
 *
 * Sibling of `sendgrid_keys` / `mailgun_keys`, and deliberately the same shape,
 * so the admin UI, the "test" action and the credential-selection dropdown all
 * behave identically across providers. The differences are Twilio's own:
 * it authenticates an (Account SID, Auth Token) pair over HTTP Basic, and every
 * message must name a sender that the account owns — either a phone number
 * (`from_number`) or a Messaging Service (`messaging_service_sid`).
 *
 * `auth_token` is stored ENCRYPTED at rest (Model cast `encrypted`), not hashed:
 * it has to be decryptable to sign API requests. Admin responses only ever
 * expose a masked preview, never the raw token.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('twilio_configs', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);                // friendly label
            $table->string('account_sid', 64);          // "AC…"
            $table->text('auth_token');                 // encrypted at rest (Model cast)

            // A send needs exactly one sender identity. Both are nullable at the
            // column level and validated as "one or the other" in the Form
            // Request — a Messaging Service is the better choice at volume
            // (number pooling, sticky sender), a single number is simpler.
            $table->string('from_number', 20)->nullable();
            $table->string('messaging_service_sid', 64)->nullable();

            $table->string('status', 10)->default('active'); // active|inactive
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('twilio_configs');
    }
};
