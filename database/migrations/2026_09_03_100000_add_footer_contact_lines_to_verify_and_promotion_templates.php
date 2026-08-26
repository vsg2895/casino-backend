<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gives the verify and per-site promotion emails the same footer identity block
 * the post-verification promotion already has: postal address · contact email,
 * then the copyright line.
 *
 * That block is not decoration. A physical postal address and a monitored reply
 * address are what CAN-SPAM and the GDPR expect of commercial mail, and their
 * absence is a spam signal in its own right — these two templates were the only
 * ones sending without them.
 *
 * WHAT EACH TABLE WAS MISSING differs, so the columns added differ:
 *
 *  - site_verify_emails already had `copyright_text`, and needed the address and
 *    contact lines plus `hidden_blocks` — it had no reversible-block mechanism
 *    at all, so its new fields could not otherwise be hidden and restored.
 *  - site_promotion_emails already had `hidden_blocks`, and needed all three
 *    text fields.
 *
 * Column widths match `verification_promotion_emails` exactly so the same copy
 * fits in every template.
 *
 * `site_verify_emails.unsubscribe_enabled` is deliberately left alone rather than
 * folded into `hidden_blocks`: it gates the opt-out LINK, which is a compliance
 * element with its own admin control, not a content block. Merging them would
 * churn deployed behaviour for no visible gain.
 *
 * Nullable throughout, so every existing row renders exactly as it does today
 * until an operator fills the fields in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_verify_emails', function (Blueprint $table): void {
            $table->string('postal_address', 300)->nullable()->after('footer_note');
            $table->string('contact_email', 180)->nullable()->after('postal_address');
            $table->json('hidden_blocks')->nullable()->after('unsubscribe_enabled');
        });

        Schema::table('site_promotion_emails', function (Blueprint $table): void {
            $table->string('postal_address', 300)->nullable()->after('disclaimer_text');
            $table->string('contact_email', 180)->nullable()->after('postal_address');
            $table->string('copyright_text', 200)->nullable()->after('contact_email');
        });
    }

    public function down(): void
    {
        Schema::table('site_verify_emails', function (Blueprint $table): void {
            $table->dropColumn(['postal_address', 'contact_email', 'hidden_blocks']);
        });

        Schema::table('site_promotion_emails', function (Blueprint $table): void {
            $table->dropColumn(['postal_address', 'contact_email', 'copyright_text']);
        });
    }
};
