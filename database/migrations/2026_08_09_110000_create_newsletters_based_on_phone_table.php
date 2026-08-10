<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phone numbers subscribed to SMS notifications — a STANDALONE list.
 *
 * Independent by design. It holds no site_id, no client_id, no foreign key and
 * no relationship to `newsletters`, `clients` or anything else; every query in
 * the phone-newsletter feature reads this table and only this table.
 *
 * WHICH COLUMNS EXIST, AND WHY
 * ----------------------------
 * The email side was analysed column by column before anything was copied here.
 * `newsletters` carries site_id, full_name, verified, two unsubscribe tokens and
 * deleted_at. Almost none of that has a job to do in an SMS workflow:
 *
 *  - site_id — omitted. An email's From domain and template are per-site, which
 *    is why `newsletters` is site-scoped. An SMS carries no site branding: the
 *    sender is the Twilio number/Messaging Service and the body is typed per
 *    send. Imported spreadsheets of numbers carry no site attribution either.
 *    This follows the `warmup_emails` precedent (also deliberately global), and
 *    like it, adding site_id later would be purely additive.
 *  - full_name — omitted. It exists on `newsletters` to personalise templates;
 *    the SMS body has no template variables.
 *  - verified — omitted. It is the double-opt-in flag set by clicking an emailed
 *    link. No such confirmation channel is built here, so the column would never
 *    be written and would only invite filtering on a value that means nothing.
 *  - unsubscribe_token / promotion_unsubscribe_token — omitted. They exist to
 *    put an opaque identifier in an unsubscribe URL. SMS opt-out is "reply STOP",
 *    which Twilio handles at the account level; there is no URL to sign.
 *  - deleted_at — omitted. The brief asks for delete, not a trash/restore view,
 *    and `warmup_emails` established that a list with no trash UI should not
 *    carry soft deletes. Deletes here are real.
 *
 * `opted_out` is the ONE field with no direct counterpart above, and it is not a
 * copy — it is the SMS equivalent of the thing the email audience query spends
 * most of its effort on. ScheduleRecipientService excludes anyone present in
 * `unsubscribes`; without an equivalent, every bulk run would re-send to numbers
 * that already replied STOP, which Twilio rejects (error 21610) and which is a
 * compliance problem, not just wasted spend. A boolean on the row keeps the
 * feature standalone where a second opt-out table would not.
 *
 * INDEXES
 * -------
 *  - unique(phone) — each number exists once. This is what makes a re-import
 *    idempotent and lets the importer use insertOrIgnore instead of a per-row
 *    existence check. Numbers are normalised to E.164 before they are written
 *    (see App\Support\Phone\PhoneNumber), so the constraint compares like with
 *    like rather than "+1 555 0100" against "+15550100".
 *  - index(created_at) — the admin listing orders by it, and every date filter
 *    in the feature is a range over it.
 *  - index(opted_out, created_at) — the exact shape of the bulk-send audience
 *    query: opted-out numbers excluded, then a created_at range, then keyset
 *    ordering on (created_at, id). InnoDB appends the primary key to every
 *    secondary index, so this one serves the filter, the range and the cursor
 *    without a sort.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('newsletters_based_on_phone', function (Blueprint $table): void {
            $table->id();

            // E.164, e.g. "+15551234567" — 16 chars at most, 20 for headroom.
            $table->string('phone', 20)->unique();

            // Set when Twilio reports the number has opted out (STOP), so the
            // next run excludes it. See the class docblock.
            $table->boolean('opted_out')->default(false);
            $table->timestamp('opted_out_at')->nullable();

            $table->timestamps();

            $table->index('created_at');
            $table->index(['opted_out', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('newsletters_based_on_phone');
    }
};
