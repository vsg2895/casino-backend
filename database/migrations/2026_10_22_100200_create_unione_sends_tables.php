<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The send log: run → chunk → recipient.
 *
 * ── Why THREE tables where Warmup uses two ──────────────────────────────────
 *
 * Warmup has a run and a per-recipient history. UniOne needs a level between
 * them, because the CHUNK is the unit the API call maps to and the unit that has
 * to be committed atomically.
 *
 * `idempotence_key` derives from (send_id, chunk_index) — but UniOne honours it
 * for ONE MINUTE, while this queue's `retry_after` is 1200s. Those windows do
 * not overlap, so by the time a retry runs the key has expired and UniOne treats
 * the retry as a brand-new send. The key therefore protects only against a fast
 * double-dispatch; it cannot protect against a normal retry.
 *
 * `unione_send_chunks.committed_at` is what actually prevents duplicates: it is
 * written in the SAME TRANSACTION that stamps the chunk's recipients, and a
 * retry that finds it set returns without calling the API. Without this row
 * there would be nowhere to record "this chunk already went", and the design
 * would be resting on a guarantee the API does not give.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('unione_sends')) {
            Schema::create('unione_sends', function (Blueprint $table): void {
                $table->id();
                // restrictOnDelete: a send log that outlives its key is still the
                // record of what was mailed, and deleting a key must not erase it.
                $table->foreignId('unione_api_key_id')->constrained('unione_api_keys')->restrictOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

                $table->string('subject', 255);
                $table->string('from_email', 255);
                $table->string('from_name', 120)->nullable();
                $table->string('reply_to', 255)->nullable();
                $table->longText('html_body');
                $table->longText('plaintext_body')->nullable();

                $table->unsignedInteger('requested_count');
                $table->unsignedSmallInteger('cooldown_hours')->default(0);
                $table->unsignedInteger('eligible_count')->default(0);
                $table->unsignedInteger('accepted_count')->default(0);
                $table->unsignedInteger('failed_count')->default(0);
                $table->unsignedSmallInteger('chunk_count')->default(0);

                // queued | sending | completed | failed | cancelled
                $table->string('status', 12)->default('queued');
                $table->text('error')->nullable();
                $table->timestamp('completed_at')->nullable();

                $table->timestamps();

                $table->index(['status', 'id'], 'unione_sends_status_idx');
                $table->index('created_at', 'unione_sends_created_idx');
            });
        }

        if (! Schema::hasTable('unione_send_chunks')) {
            Schema::create('unione_send_chunks', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('unione_send_id')->constrained('unione_sends')->cascadeOnDelete();
                $table->unsignedSmallInteger('chunk_index');

                // Max 64 chars per the spec.
                $table->string('idempotence_key', 64);
                // UniOne's own handle for the batch, echoed by every webhook event.
                $table->string('job_id', 64)->nullable();

                $table->unsignedSmallInteger('recipient_count');
                $table->unsignedSmallInteger('accepted_count')->default(0);
                $table->unsignedSmallInteger('failed_count')->default(0);

                // pending | committed | failed
                $table->string('status', 12)->default('pending');
                $table->unsignedSmallInteger('http_status')->nullable();
                // UniOne's API error code — 204 means "every address failed".
                $table->unsignedSmallInteger('api_error_code')->nullable();
                $table->text('error')->nullable();
                $table->unsignedInteger('latency_ms')->nullable();
                $table->unsignedTinyInteger('attempts')->default(0);

                /*
                 * The real duplicate guard. Set inside the transaction that
                 * stamps this chunk's recipients; a retry checks it first.
                 */
                $table->timestamp('committed_at')->nullable();

                $table->timestamps();

                // One row per chunk of a send — and the reason a retry can find
                // its own chunk rather than creating a second one.
                $table->unique(['unione_send_id', 'chunk_index'], 'unione_send_chunks_unique');
                $table->index('job_id', 'unione_send_chunks_job_idx');
            });
        }

        if (Schema::hasTable('unione_send_recipients')) {
            return;
        }

        Schema::create('unione_send_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('unione_send_id')->constrained('unione_sends')->cascadeOnDelete();
            $table->foreignId('unione_send_chunk_id')->nullable()->constrained('unione_send_chunks')->nullOnDelete();
            // nullOnDelete: the log of what was mailed survives a receiver being
            // removed from the list.
            $table->foreignId('unione_receiver_id')->nullable()->constrained('unione_receivers')->nullOnDelete();

            // Denormalised so the log reads correctly after a receiver is gone.
            $table->string('email', 255);

            // accepted | failed
            $table->string('status', 10);
            // The failed_emails reason verbatim: invalid, duplicate,
            // temporary_unavailable, permanent_unavailable, unsubscribed,
            // complained, blocked.
            $table->string('reason', 32)->nullable();

            $table->timestamps();

            $table->index(['unione_send_id', 'status'], 'unione_send_recipients_send_idx');
            $table->index('email', 'unione_send_recipients_email_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unione_send_recipients');
        Schema::dropIfExists('unione_send_chunks');
        Schema::dropIfExists('unione_sends');
    }
};
