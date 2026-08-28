<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Button label size for the two PER-SITE templates, matching the control the
 * post-verification promotion already has.
 *
 * Two tables, one migration: they gain the identical column for the identical
 * reason, and splitting them would mean two files that must be applied together
 * anyway.
 *
 * NULL DEFAULT on both, and null means "exactly as it renders today" — the
 * verify email keeps its 15px and the promotion offer its 18px. Note those two
 * defaults DIFFER, and deliberately so: each falls back to whatever its own
 * layout already used, so no existing email changes appearance on deploy.
 * Nothing needs backfilling.
 *
 * unsignedTinyInteger caps at 255; the real bounds live in the Form Requests so
 * the validation message can quote the range.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_verify_emails', function (Blueprint $table): void {
            $table->unsignedTinyInteger('button_text_font_size')->nullable()->after('unsubscribe_label');
        });

        Schema::table('site_promotion_emails', function (Blueprint $table): void {
            $table->unsignedTinyInteger('button_text_font_size')->nullable()->after('top_button_url');
        });
    }

    public function down(): void
    {
        Schema::table('site_verify_emails', function (Blueprint $table): void {
            $table->dropColumn('button_text_font_size');
        });

        Schema::table('site_promotion_emails', function (Blueprint $table): void {
            $table->dropColumn('button_text_font_size');
        });
    }
};
