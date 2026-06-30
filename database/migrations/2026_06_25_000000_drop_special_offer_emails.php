<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the "special offer emails" subsystem: the on-site modal, the email
 * template fields, and the collected leads table. Safe to run on both existing
 * databases (drops them) and fresh installs (no-op).
 */
return new class extends Migration
{
    private const COLUMNS = [
        'modal_enabled',
        'modal_title',
        'email_title',
        'email_link',
        'email_link_text',
        'email_banner_image',
    ];

    public function up(): void
    {
        Schema::dropIfExists('special_offer_leads');

        $existing = array_filter(
            self::COLUMNS,
            fn (string $col) => Schema::hasColumn('special_offers', $col),
        );

        if ($existing !== []) {
            Schema::table('special_offers', function (Blueprint $table) use ($existing): void {
                $table->dropColumn(array_values($existing));
            });
        }
    }

    public function down(): void
    {
        // Irreversible by design — the emails feature was removed.
    }
};
