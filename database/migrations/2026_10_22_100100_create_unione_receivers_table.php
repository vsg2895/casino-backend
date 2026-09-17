<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The UniOne recipient list.
 *
 * ENTIRELY SEPARATE from `newsletters`. Nothing in the UniOne feature reads or
 * writes the existing subscriber tables — that isolation is the point, and it is
 * why this table carries its own email column rather than a foreign key.
 *
 * ── Consent is NOT NULL, and that is a compliance control ───────────────────
 *
 * UniOne's terms require documented consent, and an account that mails
 * unconsented addresses gets closed. So `consent_source` and `consent_at` are
 * NOT NULL at the schema level AND required by `scopeSendable()`. A caller
 * cannot forget: the only way any send path selects rows is through that scope.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('unione_receivers')) {
            return;
        }

        Schema::create('unione_receivers', function (Blueprint $table): void {
            $table->id();
            $table->string('email', 255);
            $table->string('name', 160)->nullable();

            // active | unsubscribed | bounced | complained | suppressed
            $table->string('status', 14)->default('active');

            // Where the consent came from, and when. Both required.
            $table->string('consent_source', 120);
            $table->timestamp('consent_at');

            $table->timestamp('last_sent_at')->nullable();
            $table->string('last_status', 32)->nullable();
            $table->unsignedInteger('send_count')->default(0);
            $table->unsignedInteger('bounce_count')->default(0);
            $table->unsignedInteger('complaint_count')->default(0);

            /*
             * Set when UniOne reports `temporary_unavailable`.
             *
             * The brief asks for "keep active, ineligible for 3 days". Storing a
             * timestamp rather than flipping the status is what lets the address
             * stay `active` — a status change would need a second job to undo it
             * and would lie about why the address is quiet.
             */
            $table->timestamp('retry_after')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // One row per address.
            $table->unique('email', 'unione_receivers_email_unique');

            /*
             * THE batch-selection index.
             *
             * `status` is equality and leads; `last_sent_at` then supplies BOTH
             * the cooldown range predicate and the ASC ordering from the same
             * B-tree, so the send query needs no filesort. Nulls sort first in
             * MySQL ascending order, which is exactly the "never contacted goes
             * first" rule the brief asks for — no NULLS FIRST clause needed.
             */
            $table->index(['status', 'last_sent_at'], 'unione_receivers_batch_idx');

            // The admin listing: newest first, within a status filter.
            $table->index(['status', 'created_at'], 'unione_receivers_listing_idx');

            /*
             * A bare (last_sent_at) index is NOT created.
             *
             * The brief lists one. It would be a strict prefix-subset of
             * unione_receivers_batch_idx for every query that also filters
             * status — which is every send query, because scopeSendable() always
             * constrains status. An index that no query chooses is pure write
             * cost on a table that takes bulk imports. EXPLAIN at 100k rows is in
             * the performance report; if a query ever needs it, it is one
             * migration away.
             */
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unione_receivers');
    }
};
