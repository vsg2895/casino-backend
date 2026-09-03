<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recipients reachable through a stored Mailgun credential.
 *
 * A SEPARATE table from `newsletters` on purpose: that one is scoped per site,
 * carries four per-stream opt-in tokens and belongs to the public subscription
 * flow. Nothing here touches it.
 *
 * Column notes that matter at 100k rows:
 *  - `email` is uniquely indexed on its own (not composite with a site, as
 *    newsletters is) because this pool is global to the credential set.
 *  - `last_sent_at` is indexed: the cooldown filter reads it on EVERY selection,
 *    and at 100k rows an unindexed scan there is the whole query.
 *  - `(is_active, unsubscribed_at, last_sent_at)` is a composite covering the
 *    exact predicate the batch scope uses, so selection stays an index range
 *    scan rather than a filesort.
 *  - `(created_at, id)` backs the keyset pagination the sender streams with —
 *    the same technique WarmupRecipientService uses to hold memory flat.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mailgun_receivers')) {
            return;
        }

        Schema::create('mailgun_receivers', function (Blueprint $table): void {
            $table->id();

            // Stored lowercase + trimmed by the model mutator, so the unique
            // index is meaningful — "A@x.com" and "a@x.com" are one person.
            $table->string('email', 255)->unique();
            $table->string('name', 255)->nullable();

            // How the row arrived: 'import' | 'manual'.
            $table->string('source', 10)->default('manual');

            // WHERE this address came from, and when that was recorded. Required
            // at import time by the Form Request — an address with no provenance
            // is exactly what makes a list unsendable.
            $table->string('consent_source', 255)->nullable();
            $table->timestamp('consent_recorded_at')->nullable();

            // Generated on create. The credential for one-click unsubscribe, so
            // it must be unguessable and unique across the table.
            $table->string('unsubscribe_token', 64)->unique();

            $table->boolean('is_active')->default(true);
            $table->timestamp('unsubscribed_at')->nullable();

            // Denormalised mirror of the newest successful send. The cooldown
            // filter reads this rather than aggregating the history table, which
            // is what keeps selection cheap at volume.
            $table->timestamp('last_sent_at')->nullable()->index();
            $table->unsignedInteger('sent_count')->default(0);
            $table->text('last_error')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Covers the batch predicate end to end.
            $table->index(
                ['is_active', 'unsubscribed_at', 'last_sent_at'],
                'mailgun_receivers_selection_index',
            );
            // Keyset pagination order.
            $table->index(['created_at', 'id'], 'mailgun_receivers_keyset_index');
        });
    }

    public function down(): void
    {
        // Only ever drops a table this migration created; nothing else writes here.
        Schema::dropIfExists('mailgun_receivers');
    }
};
