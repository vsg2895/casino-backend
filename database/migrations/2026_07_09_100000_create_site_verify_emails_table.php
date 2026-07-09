<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-site "verify your email" template. Mirrors site_email_templates exactly
 * (one row per site, every visible string editable per site) — a separate,
 * independently editable template the admin manages alongside the subscription
 * and promotion emails.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_verify_emails', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->unique()->constrained()->cascadeOnDelete();

            $table->string('from_name', 120);
            $table->string('from_email', 180);

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

            $table->string('accent_color', 9)->default('#4f1d96');
            $table->boolean('active')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_verify_emails');
    }
};
