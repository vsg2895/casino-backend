<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records whether a member agreed to receive marketing, and WHEN.
 *
 * Registering an account is not consent to be marketed to. The
 * post-verification promotion now reaches registered members, so the thing that
 * was missing is the member's own say in it — an unticked box on the
 * registration form, and a column to remember the answer.
 *
 * A TIMESTAMP, not a boolean. `true` tells you somebody agreed; it does not tell
 * you when, which is the question actually asked when a complaint arrives or a
 * regulator does. The column doubles as the flag — NULL means no consent — so
 * nothing needs both.
 *
 * NULL IS THE DEFAULT, for new rows and every existing one. Every member who
 * registered before this shipped is therefore excluded from the promotion, which
 * is the only defensible reading: they were never asked. Note that the earlier
 * migration already marked those members as claimed, so this is the second
 * independent reason they will not be mailed — deliberately belt and braces,
 * because the cost of being wrong here is a complaint, not a bug report.
 *
 * NO INDEX of its own. The sweep's existing
 * `(site_id, verification_promotion_sent_at, email_verified_at)` index already
 * narrows the scan to a handful of rows; adding consent to it would widen the
 * key for a condition that filters almost nothing once the others have run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forum_users', function (Blueprint $table): void {
            $table->timestamp('marketing_consent_at')->nullable()->after('email_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('forum_users', function (Blueprint $table): void {
            $table->dropColumn('marketing_consent_at');
        });
    }
};
