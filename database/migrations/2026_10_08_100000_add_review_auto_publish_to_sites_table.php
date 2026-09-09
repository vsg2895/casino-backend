<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-site switch between PRE- and POST-moderation of visitor reviews.
 *
 * ON (the default): a submitted review is published the moment it is written and
 * a moderator hides the bad ones afterwards. OFF: the original behaviour — every
 * review waits in the pending queue until someone approves it.
 *
 * DEFAULT TRUE, unlike `reviews_enabled`, and the difference is deliberate. This
 * column cannot open anything on a site that is not already showing reviews: it
 * is only ever read after `reviews_enabled` has passed. So the safe default is
 * the one the operator asked for, not the one that silently keeps their
 * submissions invisible.
 *
 * Existing PENDING rows are NOT retro-published. They were written under a
 * promise of moderation and were never seen by anyone; publishing them here
 * would push unreviewed text live as a side effect of a schema change.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sites') || Schema::hasColumn('sites', 'review_auto_publish')) {
            return;
        }

        Schema::table('sites', function (Blueprint $table): void {
            $table->boolean('review_auto_publish')->default(true)->after('reviews_enabled');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('sites') || ! Schema::hasColumn('sites', 'review_auto_publish')) {
            return;
        }

        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn('review_auto_publish');
        });
    }
};
