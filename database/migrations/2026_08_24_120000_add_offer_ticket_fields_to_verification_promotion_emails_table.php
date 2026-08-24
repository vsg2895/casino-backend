<?php

declare(strict_types=1);

use App\Models\VerificationPromotionEmail;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two more editable pieces for the reworked post-verification promotion layout:
 *
 *  - `confirmation_text` — the thin green strip at the very top that states the
 *    one confirmation fact ("your email is confirmed") in a single line, freeing
 *    the heading to speak about the OFFER instead of the confirmation.
 *
 *  - `offer_terms` — JSON list of {label,value} pairs that turn the plain grey
 *    box into an offer "ticket": the bonus amount headline (highlight_text) over
 *    a row of the terms a subscriber actually checks before clicking — Wagering,
 *    Min deposit, Offer ends.
 *
 * Both are nullable / defaulted so the single existing row upgrades cleanly, and
 * the row is backfilled with the new defaults so the template renders complete on
 * first load. Existing edits to other fields are left untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('verification_promotion_emails', function (Blueprint $table): void {
            $table->string('confirmation_text', 200)->nullable()->after('eyebrow_text');
            // Ordered list of {label,value} offer-ticket terms.
            $table->json('offer_terms')->nullable()->after('highlight_text');
        });

        $defaults = VerificationPromotionEmail::defaults();

        DB::table('verification_promotion_emails')
            ->whereNull('offer_terms')
            ->update([
                'confirmation_text' => $defaults['confirmation_text'] ?? null,
                'offer_terms'       => json_encode($defaults['offer_terms'] ?? []),
            ]);
    }

    public function down(): void
    {
        Schema::table('verification_promotion_emails', function (Blueprint $table): void {
            $table->dropColumn(['confirmation_text', 'offer_terms']);
        });
    }
};
