<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ingested UniOne webhook events, and the suppression audit trail.
 *
 * ── Idempotency is a UNIQUE INDEX, not an application check ─────────────────
 *
 * UniOne re-delivers a webhook until it gets a 200, so the same event WILL
 * arrive more than once. "Must not double-count" cannot be enforced by reading
 * first and writing second — two concurrent deliveries both read "not present"
 * and both write. A unique key on the event's natural identity is the only
 * place a second delivery can be refused by something that cannot race.
 *
 * The identity is (job_id, email, event_name, status, event_time): UniOne sends
 * no event id, and those five together are what distinguishes one real event
 * from another. A genuine second open of the same message has a different
 * `event_time`, so it still counts — which is correct.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('unione_webhook_events')) {
            Schema::create('unione_webhook_events', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('unione_api_key_id')->nullable()->constrained('unione_api_keys')->nullOnDelete();

                $table->string('event_name', 40);
                $table->string('job_id', 64)->nullable();
                $table->string('email', 255)->nullable();
                // sent, delivered, opened, clicked, unsubscribed, soft_bounced,
                // hard_bounced, spam
                $table->string('status', 24)->nullable();
                $table->timestamp('event_time')->nullable();

                $table->string('delivery_status', 60)->nullable();
                $table->text('destination_response')->nullable();
                $table->string('url', 500)->nullable();

                // The whole payload, so a field UniOne adds later is not lost.
                $table->json('payload')->nullable();

                // Whether this event moved the receiver's state.
                $table->boolean('applied')->default(false);

                $table->timestamp('created_at')->nullable();

                /*
                 * A hash rather than the five columns directly: `event_time` and
                 * a 255-char email push a composite unique past InnoDB's 3072-byte
                 * key limit once utf8mb4 is accounted for. The hash is fixed
                 * width and collision-resistant enough for a dedup key.
                 */
                $table->char('event_hash', 64);
                $table->unique('event_hash', 'unione_webhook_events_dedup');

                $table->index(['email', 'id'], 'unione_webhook_events_email_idx');
                $table->index(['job_id'], 'unione_webhook_events_job_idx');
            });
        }

        if (Schema::hasTable('unione_suppression_actions')) {
            return;
        }

        Schema::create('unione_suppression_actions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('unione_api_key_id')->nullable()->constrained('unione_api_keys')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('email', 255);
            // add | remove
            $table->string('action', 10);
            $table->string('cause', 32)->nullable();
            $table->boolean('succeeded')->default(true);
            $table->text('error')->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['email', 'id'], 'unione_suppression_actions_email_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unione_suppression_actions');
        Schema::dropIfExists('unione_webhook_events');
    }
};
