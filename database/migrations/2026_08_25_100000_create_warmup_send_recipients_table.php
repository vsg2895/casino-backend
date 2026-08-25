<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-address warmup history, plus the two columns the new selection needs.
 *
 * Why a second table instead of more columns on `warmup_emails`: an address can
 * be warmed many times, from different sites, with different templates. A scalar
 * column can only remember the LAST one, which is why `warmup_emails.last_sent_at`
 * cannot answer "what did this address receive, and when". This table is the
 * event log; `last_sent_at` stays as the denormalised "current state" column.
 *
 * THE INVARIANT between the two: `warmup_emails.last_sent_at` equals the newest
 * `warmup_send_recipients.sent_at` for that address WITH status = sent. Both are
 * written together by {@see \App\Jobs\SendWarmupBatchJob}. Failures are recorded
 * here for the audit but deliberately do NOT advance `last_sent_at`, so a bounced
 * address is retried by the next run instead of silently serving its cooldown.
 *
 * The cooldown filter reads `last_sent_at` rather than joining this table: it is
 * one indexed column on the row being selected, versus an index probe per
 * candidate address. The log stays the source of truth for the audit; the column
 * stays the source of truth for selection.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warmup_send_recipients', function (Blueprint $table): void {
            $table->id();

            // The run this attempt belongs to. cascadeOnDelete because a row has
            // no meaning without its run.
            $table->foreignId('warmup_send_id')->constrained()->cascadeOnDelete();

            // The address row while it still exists. nullOnDelete, paired with the
            // denormalised `email` below: an audit trail has to survive the thing
            // it describes being deleted. Same choice, for the same reason, as
            // `phone_sms_histories.phone` and `promotion_email_histories.email`.
            $table->foreignId('warmup_email_id')->nullable()->constrained()->nullOnDelete();

            // Which site's template was rendered. Denormalised from `warmup_sends`
            // so the history filters and renders without a join.
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();

            $table->string('email');

            // EmailTemplateCatalog key: subscribe | promotion.
            $table->string('template', 20);

            // sent | failed — see WarmupSendRecipient::STATUSES.
            $table->string('status', 10);

            // The transport's message when status = failed. Null on success.
            $table->text('error')->nullable();

            // When the attempt happened. Separate from created_at: the row is
            // written in buffered groups, so created_at is when it was FLUSHED.
            $table->timestamp('sent_at');

            $table->timestamps();

            // The listing's ORDER BY sent_at DESC, id DESC. The `id` tiebreaker is
            // not optional — a batch stamps up to 100 rows with one timestamp, and
            // without it paging would repeat some rows and skip others.
            $table->index(['sent_at', 'id'], 'warmup_recipients_sent_index');

            // Prefix search on the address, matching every other admin listing.
            $table->index('email', 'warmup_recipients_email_index');

            // Deliberately NOT indexed further: this table is written in bulk
            // after every run, and `warmup_send_id` / `warmup_email_id` / `site_id`
            // already carry the indexes their foreign keys require.
        });

        Schema::table('warmup_sends', function (Blueprint $table): void {
            // The cooldown this run was configured with, so the history explains
            // its own audience. NULL means no cooldown was applied — which is the
            // case for a "send to everyone" run.
            $table->unsignedSmallInteger('cooldown_days')->nullable()->after('requested_count');
        });

        Schema::table('warmup_emails', function (Blueprint $table): void {
            // Serves the new selection: ORDER BY created_at DESC, id DESC with a
            // cooldown predicate on last_sent_at.
            //
            // `last_sent_at` is the third column purely to make the index COVERING
            // for the filter: MySQL walks the index backwards in `created_at` order
            // and evaluates the cooldown without touching the row, so a run that
            // wants 50 addresses reads ~50 rows rather than the whole table.
            $table->index(['created_at', 'id', 'last_sent_at'], 'warmup_emails_recency_index');
        });

        // `warmup_emails_rotation_index` (last_sent_at, id) is deliberately KEPT.
        // It no longer drives the selection order, but it is exactly the index the
        // eligibility COUNT needs — that query filters on last_sent_at with no
        // ordering, and the recency index above cannot serve it.
    }

    public function down(): void
    {
        Schema::table('warmup_emails', function (Blueprint $table): void {
            $table->dropIndex('warmup_emails_recency_index');
        });

        Schema::table('warmup_sends', function (Blueprint $table): void {
            $table->dropColumn('cooldown_days');
        });

        Schema::dropIfExists('warmup_send_recipients');
    }
};
