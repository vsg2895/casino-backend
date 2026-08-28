<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Label size for the post-verification promotion's two buttons.
 *
 * ONE column for BOTH buttons, deliberately. They already share a fixed width
 * ($btnWidth in the Blade) so they read as one repeated call to action rather
 * than two unrelated controls; letting their labels differ in size would undo
 * exactly that. A second column would also be a second thing to keep in step.
 *
 * NULL DEFAULT, and null means "exactly as it renders today": the Blade falls
 * back to the hardcoded 16px. Nothing needs backfilling.
 *
 * unsignedTinyInteger caps at 255, far above anything sane for a button label;
 * the real bounds are enforced in the Form Request so the message can quote the
 * range.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('verification_promotion_emails', function (Blueprint $table): void {
            $table->unsignedTinyInteger('button_text_font_size')->nullable()->after('cta_button_url');
        });
    }

    public function down(): void
    {
        Schema::table('verification_promotion_emails', function (Blueprint $table): void {
            $table->dropColumn('button_text_font_size');
        });
    }
};
