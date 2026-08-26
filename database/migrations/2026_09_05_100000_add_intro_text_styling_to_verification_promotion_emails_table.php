<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets the post-verification promotion's intro paragraph be sized, and given a
 * panel background like the responsible-gambling notice already has.
 *
 * The intro is the line that carries the offer in words, so it is the one piece
 * of body copy an operator wants to be able to emphasise — either by making it
 * larger, or by lifting it out of the flow onto a tinted panel.
 *
 * BOTH DEFAULT TO NULL, and null means "exactly as it renders today":
 *
 *  - `intro_text_font_size` — null falls back to the hardcoded 16px.
 *  - `intro_text_background_color` — null renders the plain paragraph with no
 *    wrapper at all. Only a set colour produces the padded, rounded panel, so an
 *    existing row gains no stray box.
 *
 * Deliberately NOT part of COLOR_DEFAULTS: every colour in that map is defaulted
 * to a real value at render time, which is right for the palette but would give
 * this one a permanent background nobody asked for. Its whole meaning is "unset
 * = no panel".
 *
 * Deliberately NOT an optional block either: these are styling settings, not
 * content, and the intro paragraph itself is already hideable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('verification_promotion_emails', function (Blueprint $table): void {
            // Bounds are enforced in the Form Request rather than the column, so
            // the message can explain the range. unsignedTinyInteger caps at 255,
            // far above anything sane for body copy.
            $table->unsignedTinyInteger('intro_text_font_size')->nullable()->after('intro_text');
            $table->string('intro_text_background_color', 9)->nullable()->after('intro_text_font_size');
        });
    }

    public function down(): void
    {
        Schema::table('verification_promotion_emails', function (Blueprint $table): void {
            $table->dropColumn(['intro_text_font_size', 'intro_text_background_color']);
        });
    }
};
