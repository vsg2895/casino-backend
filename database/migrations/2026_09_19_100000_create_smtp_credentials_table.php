<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stored SMTP servers that can run a campaign against the Mailgun receiver list.
 *
 * Deliberately a sibling of `mailgun_keys`, not a variant of it: the receiver
 * targeting columns are identical so one selector serves both, and only the
 * authentication columns differ (host/port/username/password instead of
 * domain/api_key/region).
 *
 * There is no `send_enabled` column, and that is not an oversight. Mailgun
 * credentials carry one because the scheduler decides when they run; these are
 * driven only by the "Run now" button, so a scheduling flag would be a switch
 * that never gets read.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('smtp_credentials')) {
            return;
        }

        Schema::create('smtp_credentials', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120)->unique();   // friendly label, e.g. "SMTP_BIU"

            // ── Authentication ───────────────────────────────────────────────
            $table->string('host', 255);
            $table->unsignedSmallInteger('port')->default(465);
            $table->string('username', 255);
            // Encrypted at rest by the model cast, exactly like mailgun_keys.api_key.
            // text, not string: ciphertext is far longer than the plaintext.
            $table->text('password');
            // 'ssl' (implicit TLS, usually 465) | 'tls' (STARTTLS, usually 587)
            // | 'none'. Stored rather than inferred from the port: plenty of
            // servers listen for STARTTLS on non-standard ports, and guessing
            // wrong fails with a TLS error that names neither cause.
            $table->string('encryption', 5)->default('ssl');

            // The envelope sender. Unlike mailgun_keys — where from_address is
            // reference-only because the site template supplies the sender —
            // this IS read at send time: an own SMTP server has no other source
            // of identity, and most reject a From that is not its own domain.
            $table->string('from_address', 255);
            $table->string('from_name', 120)->nullable();

            $table->string('status', 10)->default('active'); // active|inactive

            // ── Receiver targeting (mirrors mailgun_keys) ────────────────────
            $table->unsignedInteger('batch_size')->default(100);
            $table->string('selection_order', 10)->default('newest');
            $table->unsignedSmallInteger('cooldown_days')->nullable()->default(1);
            $table->boolean('only_active')->default(true);
            $table->timestamp('last_run_at')->nullable();

            $table->string('message_subject', 255)->nullable();
            $table->longText('message_html')->nullable();
            $table->json('message_template')->nullable();

            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smtp_credentials');
    }
};
