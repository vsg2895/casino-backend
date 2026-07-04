<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-site promotion (marketing offer) email template.
 *
 * One row per site (unique site_id). Sibling of site_email_templates but for the
 * promotional "welcome offer" blast rather than the subscription confirmation.
 * Every visible string is editable per site so each brand controls its own copy,
 * hero image, CTA links, sender identity and button colours. Structured fields
 * are rendered into a fixed Blade layout (mail.promotion.offer). All text
 * supports {{site_name}}, {{site_url}}, {{email}}, {{year}}, {{unsubscribe_url}}
 * placeholders; body fields additionally support **bold**.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_promotion_emails', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->unique()->constrained()->cascadeOnDelete();

            // Sender identity (from_email host is validated against the verified SendGrid domain).
            $table->string('from_name', 120);
            $table->string('from_email', 180);

            // Subject + hidden preview (preheader) text.
            $table->string('subject', 200);
            $table->string('preheader', 250);

            // Hero banner: clickable image at the top of the email.
            $table->string('hero_image_url', 500)->nullable();
            // Destination for the hero image and both CTA buttons (usually the affiliate offer).
            $table->string('hero_url', 500);

            // Copy + calls to action.
            $table->string('top_button_text', 80);
            $table->string('heading', 150);
            $table->text('intro_text');       // rich (**bold**)
            $table->text('secondary_text');   // rich
            $table->string('cta_button_text', 80);
            $table->text('disclaimer_text');  // rich
            $table->string('unsubscribe_label', 80);

            // CTA button fill colour and link/accent colour (hex).
            $table->string('button_color', 9)->default('#75B636');
            $table->string('accent_color', 9)->default('#f3a333');

            // When false, the promotion is stored/editable but "send test" is blocked.
            $table->boolean('active')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_promotion_emails');
    }
};
