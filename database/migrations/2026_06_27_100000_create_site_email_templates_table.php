<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-site subscription (newsletter confirmation) email template.
 *
 * One row per site (unique site_id). Every visible string in the confirmation
 * email is editable here so each site controls its own copy, sender identity
 * and accent colour. Sending happens through the shared SendGrid mailer, but
 * the "from" identity is per-site (constrained to the SendGrid-verified domain).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_email_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->unique()->constrained()->cascadeOnDelete();

            // Sender identity (from_email host is validated against the verified SendGrid domain).
            $table->string('from_name', 120);
            $table->string('from_email', 180);

            // Subject + body copy (all support {{site_name}}, {{site_url}}, {{email}},
            // {{year}}, {{unsubscribe_url}} placeholders; body fields support **bold**).
            $table->string('subject', 200);
            $table->string('header_title', 150);
            $table->string('header_subtitle', 250);
            $table->string('heading', 150);
            $table->text('intro_text');
            $table->text('offer_text');
            $table->text('spam_notice');
            $table->text('footer_note');
            $table->string('unsubscribe_label', 80);
            $table->string('copyright_text', 200);

            // Header band / button accent colour (hex).
            $table->string('accent_color', 9)->default('#4f1d96');

            // When false, subscriptions are still stored but no confirmation email is sent.
            $table->boolean('active')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_email_templates');
    }
};
