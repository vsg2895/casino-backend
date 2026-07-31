<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets an admin REMOVE the promotion email's call-to-action elements.
 *
 * `hero_image_url` was already nullable (the hero is optional), but the two
 * buttons and their target URL were NOT NULL, so a site could never publish a
 * promotion email without them. Making them nullable is what lets the editor
 * clear a field and have the Blade layout drop that element entirely — an empty
 * button text now means "no button", not "a button with no label".
 *
 * Existing rows keep their values; nothing is rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_promotion_emails', function (Blueprint $table): void {
            $table->string('hero_url', 500)->nullable()->change();
            $table->string('top_button_text', 80)->nullable()->change();
            $table->string('cta_button_text', 80)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Restore a value first: the columns become NOT NULL again.
        foreach ([
            'hero_url'        => '{{site_url}}',
            'top_button_text' => 'View Details',
            'cta_button_text' => 'Register Your Account',
        ] as $column => $fallback) {
            \Illuminate\Support\Facades\DB::table('site_promotion_emails')
                ->whereNull($column)
                ->update([$column => $fallback]);
        }

        Schema::table('site_promotion_emails', function (Blueprint $table): void {
            $table->string('hero_url', 500)->nullable(false)->change();
            $table->string('top_button_text', 80)->nullable(false)->change();
            $table->string('cta_button_text', 80)->nullable(false)->change();
        });
    }
};
