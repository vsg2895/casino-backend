<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-site image and link overrides for the post-verification promotion.
 *
 * The promotion itself stays ONE global template: same subject, same copy,
 * same colours, same delay, same transport for every site. This table changes
 * nothing about that — it carries only the hero image and the outbound links,
 * so a site can point the same offer at its own creative and its own targets
 * without forking the template.
 *
 * Every column is nullable and null means "use the template's value". That is
 * what keeps the existing five-site behaviour intact by default: a site with
 * no row, or a row with blanks, renders exactly what it rendered before.
 *
 * Keyed by site_id rather than a slug, and deliberately NOT limited to one
 * site: the request was for Roulettingo, but hardcoding a slug is the thing
 * this codebase forbids outright, and a second site wanting the same thing is
 * then a row rather than a patch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verification_promotion_overrides', function (Blueprint $table): void {
            $table->id();

            // One row per site. Cascades, because an override describes a site
            // and is meaningless once that site is gone.
            $table->foreignId('site_id')
                ->unique()
                ->constrained()
                ->cascadeOnDelete();

            // The hero image. A stored upload path or an absolute URL — the
            // same two forms the template's own field accepts.
            $table->string('hero_image_url', 500)->nullable();

            // Every outbound link the template exposes. The unsubscribe link is
            // absent on purpose: it is minted per recipient from their own
            // token and must never be overridable, or one subscriber's opt-out
            // would act on another's. The contact mailto is absent for the same
            // class of reason — it is pinned to config.
            $table->string('hero_url', 500)->nullable();
            $table->string('top_button_url', 500)->nullable();
            $table->string('cta_button_url', 500)->nullable();
            $table->string('email_preferences_url', 500)->nullable();

            /*
             * Footer link TARGETS only — a list of URLs, no labels.
             *
             * Applied positionally over the template's own footer links, so
             * link #1 keeps its label and gains this URL. Storing labels here
             * would make this a way to change the email's TEXT for one site,
             * which is the one thing this feature must not do. A site can
             * therefore re-point "Privacy Policy" at its own page, and cannot
             * rename it to anything else.
             */
            $table->json('footer_link_urls')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_promotion_overrides');
    }
};
