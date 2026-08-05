<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stored Mailgun credentials usable as an alternative transport for scheduled
 * promotion campaigns — the Mailgun counterpart of sendgrid_keys.
 *
 * Additive only: nothing here touches sendgrid_keys or email_schedules, so
 * existing SMTP and SendGrid schedules are unaffected by this migration.
 *
 * The key value is stored ENCRYPTED at rest (Model cast `encrypted`), not in
 * plaintext — it must be decryptable to authenticate against the Mailgun API,
 * so it can't be hashed like site keys. Admin responses only ever expose a
 * masked preview, never the raw key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mailgun_keys', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);        // friendly label
            $table->string('domain', 255);      // the sending domain registered in Mailgun
            $table->text('api_key');            // encrypted at rest (Model cast)
            // Mailgun's EU accounts use a different API host; sending an EU
            // account's mail to the US endpoint fails with a misleading 401.
            $table->string('region', 2)->default('us');      // us|eu
            $table->string('status', 10)->default('active'); // active|inactive
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mailgun_keys');
    }
};
