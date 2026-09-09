<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the after-verification template's preview-site selector.
 *
 * The column existed to remember which site the admin had chosen in the editor's
 * "Preview as" dropdown, so {{site_name}} / {{site_url}} could be rendered
 * against it. That template is now pinned to Winpalack in
 * config('promotions.after_verification'), the dropdown is gone, and nothing
 * reads or writes the column any more — the request no longer validates it, the
 * resource no longer exposes it, and the admin SPA has no state for it.
 *
 * Only the preview ever used it. It never influenced a real send: the automatic
 * promotion always read the subscriber's own site, never this. So dropping it
 * cannot change who is mailed or what they receive.
 *
 * `dropConstrainedForeignId` removes the FK to `sites` before the column, which
 * plain `dropColumn` would fail on. `down()` restores the column exactly as
 * 2026_08_28_100000 created it — nullable, nullOnDelete, same position — but the
 * remembered value is gone for good; it would simply be null again.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('verification_promotion_emails')
            || ! Schema::hasColumn('verification_promotion_emails', 'preview_site_id')) {
            return;
        }

        Schema::table('verification_promotion_emails', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('preview_site_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('verification_promotion_emails')
            || Schema::hasColumn('verification_promotion_emails', 'preview_site_id')) {
            return;
        }

        Schema::table('verification_promotion_emails', function (Blueprint $table): void {
            $table->foreignId('preview_site_id')
                ->nullable()
                ->after('unsubscribe_label')
                ->constrained('sites')
                ->nullOnDelete();
        });
    }
};
