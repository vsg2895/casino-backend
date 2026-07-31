<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stored SendGrid API keys usable as an alternative transport for scheduled
 * promotion campaigns (see the provider column added to email_schedules).
 *
 * The key value is stored ENCRYPTED at rest (Model cast `encrypted`), not in
 * plaintext — it must be decryptable to authenticate against the SendGrid API,
 * so it can't be hashed like site keys. Admin responses only ever expose a
 * masked preview, never the raw key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sendgrid_keys', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);      // friendly label
            $table->text('api_key');          // encrypted at rest (Model cast)
            $table->string('status', 10)->default('active'); // active|inactive
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sendgrid_keys');
    }
};
