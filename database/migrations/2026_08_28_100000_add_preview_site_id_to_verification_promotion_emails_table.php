<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remembers which site the admin picked in the Promotion After Verification
 * editor.
 *
 * The picker was preview-only: nothing persisted it, and the editor reset it to
 * the first registered site on every load, so changing it and saving looked like
 * the change had been lost.
 *
 * NAMED `preview_site_id`, NOT `site_id`, and the distinction is the whole point.
 * This template is GLOBAL — one row serving subscribers from every site — and a
 * column called `site_id` would read as ownership and invite someone to scope the
 * template by site later. It only answers "which site's {{site_name}} /
 * {{site_url}} should the preview and test render with".
 *
 * The REAL send is unaffected and must stay that way: SendVerificationPromotionJob
 * resolves the site from the subscriber's own `newsletters.site_id`, never from
 * here.
 *
 * Nullable with nullOnDelete: no site is a valid state (none registered yet), and
 * deleting a site must not delete the template.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('verification_promotion_emails', function (Blueprint $table): void {
            $table->foreignId('preview_site_id')
                ->nullable()
                ->after('unsubscribe_label')
                ->constrained('sites')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('verification_promotion_emails', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('preview_site_id');
        });
    }
};
