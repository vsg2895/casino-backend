<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Repoints the after-verification footer contact address at the config key.
 *
 * The stored value was `info@{{site_domain}}`, which resolved against the
 * SUBSCRIBER'S site — the same per-site coupling that put "ROULETTINGO" in the
 * header. `{{contact_email}}` resolves from
 * config('promotions.after_verification.contact_email') instead, so that config
 * key is the single place the address is written.
 *
 * Narrow ON PURPOSE: it rewrites only the exact legacy string, on the one global
 * row, and only when nobody has typed their own address over it. An admin's own
 * value is left alone.
 */
return new class extends Migration
{
    private const LEGACY = 'info@{{site_domain}}';

    private const PLACEHOLDER = '{{contact_email}}';

    public function up(): void
    {
        if (! Schema::hasTable('verification_promotion_emails')) {
            return;
        }

        DB::table('verification_promotion_emails')
            ->where('contact_email', self::LEGACY)
            ->update(['contact_email' => self::PLACEHOLDER]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('verification_promotion_emails')) {
            return;
        }

        DB::table('verification_promotion_emails')
            ->where('contact_email', self::PLACEHOLDER)
            ->update(['contact_email' => self::LEGACY]);
    }
};
