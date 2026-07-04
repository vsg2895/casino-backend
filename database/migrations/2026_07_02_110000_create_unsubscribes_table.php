<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-stream unsubscribe log.
 *
 * A subscriber can opt out of each email stream independently:
 *   - "subscription" — the newsletter / subscription-confirmation stream.
 *   - "promotion"    — the marketing offer stream.
 *
 * One row records a single opt-out: which site, which email, which stream, and
 * WHEN it happened. Presence of a row is the source of truth for "is this
 * address unsubscribed from this stream?" — the subscriber row itself is left
 * intact so the other stream keeps working. No personal data ever appears in the
 * unsubscribe URL; the opaque per-stream token on `newsletters` maps back here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unsubscribes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            // 'subscription' | 'promotion'
            $table->string('type', 20);
            $table->timestamp('unsubscribed_at');
            $table->timestamps();

            // One opt-out per (site, email, stream); makes recording idempotent.
            $table->unique(['site_id', 'email', 'type']);
            $table->index(['site_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unsubscribes');
    }
};
