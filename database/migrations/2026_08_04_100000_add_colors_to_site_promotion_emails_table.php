<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Makes the promotion email's palette editable per site.
 *
 * Until now only the CTA button and the unsubscribe link were configurable; the
 * canvas and every piece of copy were hardcoded to the original dark design
 * (black background, white text). A site whose brand is light had no way to use
 * this template at all.
 *
 * Defaults reproduce the current design EXACTLY, so existing rows render
 * byte-identically until an admin changes something:
 *   background      #000000  the email canvas
 *   heading         #ffffff  the h2
 *   text            #ffffff  greeting + intro paragraph
 *   secondary text  #d9d9d9  the secondary paragraph (was rgba(255,255,255,.85))
 *   muted text      #b3b3b3  disclaimer + the line around the unsubscribe link
 *                            (was rgba(255,255,255,.7) / (.5))
 *
 * The two rgba() values become solid hex because a colour picker cannot express
 * alpha, and alpha over a now-configurable background is not predictable anyway.
 * On the black default they are visually equivalent.
 */
return new class extends Migration
{
    /** column => default */
    private const array COLUMNS = [
        'background_color'     => '#000000',
        'heading_color'        => '#ffffff',
        'text_color'           => '#ffffff',
        'secondary_text_color' => '#d9d9d9',
        'muted_text_color'     => '#b3b3b3',
    ];

    public function up(): void
    {
        Schema::table('site_promotion_emails', function (Blueprint $table): void {
            $after = 'button_color';

            foreach (self::COLUMNS as $column => $default) {
                $table->string($column, 9)->default($default)->after($after);
                $after = $column;
            }
        });
    }

    public function down(): void
    {
        Schema::table('site_promotion_emails', function (Blueprint $table): void {
            $table->dropColumn(array_keys(self::COLUMNS));
        });
    }
};
