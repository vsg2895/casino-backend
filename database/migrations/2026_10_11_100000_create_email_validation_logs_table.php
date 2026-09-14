<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit trail for every SendGrid address-validation attempt.
 *
 * Written on EVERY outcome — allowed, blocked, failed open, cache hit, skip —
 * because this table is the only record of a blocked attempt: a rejected verdict
 * creates no subscriber row, so without this the attempt leaves no trace at all.
 *
 * It also has to answer "why did we spend 2,500 credits", which needs the skips
 * and cache hits recorded alongside the real calls, not just the successes.
 *
 * PII: the email address, and nothing else. No IP, no user agent — the
 * subscriber record does not hold those either, and this table must not become
 * the reason the project starts collecting them. Rows are pruned by
 * `email-validation:prune` (default 12 months).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_validation_logs', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('email');

            // Null whenever no verdict was reached — a skip, or a failed call.
            $table->string('verdict', 20)->nullable();
            $table->decimal('score', 5, 4)->nullable();
            // The parsed `result.checks` tree, stored whole so the admin detail
            // view can show what SendGrid actually said rather than our summary.
            $table->json('checks')->nullable();
            $table->string('suggestion')->nullable();

            // What we told SendGrid this call was for: "subscribe_<site_slug>".
            $table->string('source');

            // The DECISION, which is the column this table exists for:
            //   allowed     - verdict passed, verify email sent
            //   blocked     - verdict rejected, NO subscriber and NO verify email
            //   failed_open - no usable result, treated as before this feature
            $table->enum('outcome', ['allowed', 'blocked', 'failed_open']);

            // Neither of these spent a credit.
            $table->boolean('was_cached')->default(false);
            $table->boolean('was_skipped')->default(false);
            $table->string('skip_reason')->nullable();

            $table->unsignedSmallInteger('http_status')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();

            // 'YYYY-MM' in UTC. Denormalised so the monthly quota report is a
            // grouped count on an indexed column rather than a date function
            // over every row, which no index could serve.
            $table->char('quota_month', 7);

            $table->timestamps();

            $table->index(['site_id', 'created_at']);
            $table->index('verdict');
            $table->index('email');
            $table->index('quota_month');
            $table->index('outcome');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_validation_logs');
    }
};
