<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Brings the shop's emails up to what the platform's promotion templates can
 * do, and gives it an imported address list of its own.
 *
 * TEMPLATES gain a sender, preview text, a hero image, a second paragraph,
 * fine print and a palette — the same vocabulary as
 * `site_promotion_emails`, so an operator who knows that screen knows this
 * one. The columns are NOT shared with it: that table is per casino site and
 * is read by the campaign pipeline, none of which this shop touches.
 *
 * RECEIVERS are the shop's imported lists, deliberately separate from
 * `perfume_subscribers`:
 *
 *   subscribers — people who typed their address into the storefront
 *   receivers   — addresses the shop uploaded from a spreadsheet
 *
 * Same split the platform draws between `newsletters` and Custom Receivers,
 * and for the same reason: one list is consent the shop collected itself and
 * the other is a list it brought with it. A campaign can go to either, or to
 * both, but the shop always knows which it is mailing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('perfume_email_templates', function (Blueprint $table): void {
            // Sender. Empty falls back to the shop's own address (Settings).
            $table->string('from_name')->nullable()->after('kind');
            $table->string('from_email')->nullable()->after('from_name');
            // The grey line a mail client shows beside the subject.
            $table->string('preheader')->nullable()->after('subject');

            $table->string('hero_image_url')->nullable()->after('heading');
            $table->string('hero_url')->nullable()->after('hero_image_url');
            // A second paragraph, under the button.
            $table->text('secondary_text')->nullable()->after('button_url');
            $table->text('disclaimer_text')->nullable()->after('secondary_text');

            // Palette. Null means "use the default" — see
            // App\Perfume\Models\EmailTemplate::COLOR_DEFAULTS.
            foreach ([
                'background_color', 'body_background_color', 'heading_color', 'text_color',
                'secondary_text_color', 'muted_text_color', 'button_color', 'button_text_color',
                'accent_color',
            ] as $color) {
                $table->string($color, 7)->nullable();
            }
        });

        Schema::create('perfume_receivers', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->unique();
            $table->string('name')->nullable();
            $table->boolean('active')->default(true);
            // Unsubscribe credential, exactly as on perfume_subscribers:
            // $hidden on the model, never in an API response.
            $table->string('token', 64)->unique();
            // Where the address came in from — the uploaded file's name.
            $table->string('source')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();

            $table->index('active');
            $table->index('source');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('perfume_receivers');

        Schema::table('perfume_email_templates', function (Blueprint $table): void {
            $table->dropColumn([
                'from_name', 'from_email', 'preheader', 'hero_image_url', 'hero_url',
                'secondary_text', 'disclaimer_text',
                'background_color', 'body_background_color', 'heading_color', 'text_color',
                'secondary_text_color', 'muted_text_color', 'button_color', 'button_text_color',
                'accent_color',
            ]);
        });
    }
};
