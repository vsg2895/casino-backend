<?php

declare(strict_types=1);

use App\Models\VerificationPromotionEmail;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Brings the post-verification promotion footer up to commercial-email standards
 * (CAN-SPAM) and deliverability best practice:
 *
 *  - `postal_address`          — the mandatory physical postal address;
 *  - `age_disclaimer_text`     — the 18+ line stated in the email itself;
 *  - `reason_text`             — why the reader is receiving this (reduces spam
 *                                complaints — they unsubscribe rather than report);
 *  - `contact_email`           — a MONITORED reply-accepting mailbox (info@…);
 *  - `email_preferences_*`     — a "fewer emails" option beside Unsubscribe;
 *  - `footer_link_color`       — footer links get their own, higher-contrast
 *                                colour so Unsubscribe is not visually buried.
 *
 * The single existing row is backfilled with the new defaults, and its footer
 * contrast colour + tagline + copyright are reset to the reworked values so the
 * live template matches the redesign without an admin re-save.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('verification_promotion_emails', function (Blueprint $table): void {
            $table->string('reason_text', 300)->nullable()->after('affiliate_disclosure_text');
            $table->string('age_disclaimer_text', 200)->nullable()->after('reason_text');
            $table->string('postal_address', 300)->nullable()->after('age_disclaimer_text');
            $table->string('contact_email', 180)->nullable()->after('postal_address');
            $table->string('email_preferences_label', 60)->nullable()->after('contact_email');
            $table->string('email_preferences_url', 300)->nullable()->after('email_preferences_label');
            $table->string('footer_link_color', 9)->nullable()->after('footer_text_color');
        });

        $defaults = VerificationPromotionEmail::defaults();

        DB::table('verification_promotion_emails')
            ->whereNull('reason_text')
            ->update([
                'reason_text'             => $defaults['reason_text'] ?? null,
                'age_disclaimer_text'     => $defaults['age_disclaimer_text'] ?? null,
                'postal_address'          => $defaults['postal_address'] ?? null,
                'contact_email'           => $defaults['contact_email'] ?? null,
                'email_preferences_label' => $defaults['email_preferences_label'] ?? null,
                'email_preferences_url'   => $defaults['email_preferences_url'] ?? null,
                'footer_link_color'       => $defaults['footer_link_color'] ?? null,
                // Redesign resets: higher-contrast footer text, fixed tagline,
                // and copyright without "All rights reserved".
                'footer_text_color'       => $defaults['footer_text_color'] ?? null,
                'footer_tagline'          => $defaults['footer_tagline'] ?? null,
                'copyright_text'          => $defaults['copyright_text'] ?? null,
            ]);
    }

    public function down(): void
    {
        Schema::table('verification_promotion_emails', function (Blueprint $table): void {
            $table->dropColumn([
                'reason_text', 'age_disclaimer_text', 'postal_address', 'contact_email',
                'email_preferences_label', 'email_preferences_url', 'footer_link_color',
            ]);
        });
    }
};
