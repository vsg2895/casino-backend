<?php

declare(strict_types=1);

use App\Models\SitePromotionEmail;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ONE global "promotion after verification" template, plus its settings.
 *
 * Deliberately a singleton (a single row), NOT a per-site table like
 * {@see site_promotion_emails}: this promotion is global by requirement — every
 * subscriber, from every registered site, receives the same template. Making it
 * per-site would be the bug, not the feature.
 *
 * The template columns mirror site_promotion_emails exactly, minus `site_id`, so
 * the model can extend SitePromotionEmail and reuse its render(), colour
 * defaults, rich-text handling and unsubscribe URL builder unchanged — and so
 * the existing PromotionEmail mailable and Blade layout render it as-is.
 *
 * The settings columns live here too rather than in a new key/value store: the
 * project has no generic settings table, and a second table for three columns
 * that are only ever read together with the template would be pure overhead.
 *
 * `provider` + the credential ids intentionally repeat the EmailSchedule shape
 * so PromotionMailerFactory resolves this feature's transport through exactly
 * the same path as a scheduled campaign. Unlike a campaign, the SendGrid option
 * here means the .env SENDGRID_API_KEY and leaves both credential ids NULL; the
 * columns remain for the Mailgun option and for rows saved with a stored
 * SendGrid key before that option was retired.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verification_promotion_emails', function (Blueprint $table): void {
            $table->id();

            // ── Template (same columns as site_promotion_emails, no site_id) ──
            $table->string('from_name', 120);
            $table->string('from_email', 180);
            $table->string('subject', 200);
            $table->string('preheader', 250);
            $table->string('hero_image_url', 500)->nullable();
            $table->string('hero_url', 500);
            $table->string('top_button_text', 80)->nullable();
            $table->string('heading', 150);
            $table->text('intro_text');
            $table->text('secondary_text');
            $table->string('cta_button_text', 80)->nullable();
            $table->text('disclaimer_text');
            $table->string('unsubscribe_label', 80);

            foreach (SitePromotionEmail::COLOR_DEFAULTS as $column => $default) {
                $table->string($column, 9)->default($default);
            }

            // ── Settings ──────────────────────────────────────────────────────
            // Master switch. Defaults OFF so deploying this migration cannot
            // start mailing subscribers before an admin has configured it.
            $table->boolean('active')->default(false);

            // Minutes after newsletters.verified_at (the moment the subscriber
            // clicked the verify link) at which they become eligible. See
            // DispatchVerificationPromotions for the arithmetic.
            $table->unsignedInteger('delay_minutes')->default(60);

            // Transport, resolved by PromotionMailerFactory at send time.
            $table->string('provider', 20)->default('sendgrid');
            $table->foreignId('sendgrid_key_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('mailgun_key_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_promotion_emails');
    }
};
