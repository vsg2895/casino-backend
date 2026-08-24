<?php

declare(strict_types=1);

use App\Models\VerificationPromotionEmail;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Grows the global post-verification promotion into its OWN, richer design
 * (the light "thanks for subscribing — here are your free spins" layout), which
 * no longer shares the dark per-site promotion Blade.
 *
 * The new layout carries components the original field set had no home for — an
 * eyebrow label, a star-rating highlight box, a responsible-gambling notice, a
 * footer tagline, an affiliate-disclosure line, a copyright line, an editable
 * header brand text, and a set of editable footer navigation links. Each is a
 * column here so the admin can edit (or clear) it exactly like every other
 * template string; `footer_links` is JSON because it is a small, ordered list of
 * {label,url} pairs rather than a single value.
 *
 * The extra colour columns paint the parts the shared dark palette never had:
 * the header band, the white body card, and the dark footer.
 *
 * Every new column is nullable / defaulted so the single existing row upgrades
 * cleanly; the row is then backfilled with the new design's defaults so the
 * template renders complete on first load after this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('verification_promotion_emails', function (Blueprint $table): void {
            // ── New editable content components ──────────────────────────────
            $table->string('header_brand_text', 120)->nullable()->after('unsubscribe_label');
            $table->string('eyebrow_text', 120)->nullable()->after('header_brand_text');
            $table->string('rating_stars', 20)->nullable()->after('eyebrow_text');
            $table->string('highlight_text', 120)->nullable()->after('rating_stars');
            $table->text('responsible_notice_text')->nullable()->after('highlight_text');
            $table->text('footer_tagline')->nullable()->after('responsible_notice_text');
            // Ordered list of {label,url} footer navigation links.
            $table->json('footer_links')->nullable()->after('footer_tagline');
            $table->text('affiliate_disclosure_text')->nullable()->after('footer_links');
            $table->string('copyright_text', 200)->nullable()->after('affiliate_disclosure_text');

            // ── Extra colours for the light design's new regions ─────────────
            $table->string('header_color', 9)->nullable()->after('copyright_text');
            $table->string('body_background_color', 9)->nullable()->after('header_color');
            $table->string('footer_background_color', 9)->nullable()->after('body_background_color');
            $table->string('footer_text_color', 9)->nullable()->after('footer_background_color');
        });

        // Backfill the (single) existing row for the new design. The new content
        // components are filled with defaults, and the WHOLE colour palette is
        // reset to the new light theme: this template flips from the shared dark
        // design to a light one, so the row's old dark colours (black canvas,
        // white heading) would render an unreadable email against the new layout.
        // Content the admin may have edited (heading, intro, sender, settings) is
        // deliberately left untouched.
        $defaults = VerificationPromotionEmail::defaults();

        $newFields = [
            'header_brand_text', 'eyebrow_text', 'rating_stars', 'highlight_text',
            'responsible_notice_text', 'footer_tagline', 'affiliate_disclosure_text',
            'copyright_text',
        ];

        $backfill = collect([...$newFields, ...array_keys(VerificationPromotionEmail::COLOR_DEFAULTS)])
            ->mapWithKeys(fn (string $field): array => [$field => $defaults[$field] ?? null])
            ->put('footer_links', json_encode($defaults['footer_links'] ?? []))
            ->all();

        DB::table('verification_promotion_emails')
            ->whereNull('footer_links')
            ->update($backfill);
    }

    public function down(): void
    {
        Schema::table('verification_promotion_emails', function (Blueprint $table): void {
            $table->dropColumn([
                'header_brand_text', 'eyebrow_text', 'rating_stars', 'highlight_text',
                'responsible_notice_text', 'footer_tagline', 'footer_links',
                'affiliate_disclosure_text', 'copyright_text',
                'header_color', 'body_background_color', 'footer_background_color', 'footer_text_color',
            ]);
        });
    }
};
