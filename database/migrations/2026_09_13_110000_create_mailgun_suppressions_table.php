<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Addresses that must never be sent to again, across the whole Mailgun pipeline.
 *
 * Deliberately NOT the existing `unsubscribes` table. That one is keyed
 * (site_id, email, type) — a per-site, per-stream opt-out belonging to the
 * newsletter flow — so writing Mailgun bounces into it would both change that
 * table's meaning and require a site_id this pipeline does not have. Leaving it
 * untouched is a hard requirement of this change.
 *
 * Uniqueness is on `email` alone: suppression is absolute. Once an address
 * bounces hard or complains, no credential may mail it, which is what protects
 * the sending domain's reputation.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mailgun_suppressions')) {
            return;
        }

        Schema::create('mailgun_suppressions', function (Blueprint $table): void {
            $table->id();

            // Lowercased/trimmed by the model, matching mailgun_receivers, so a
            // suppression can never be bypassed by casing.
            $table->string('email', 255)->unique();

            // 'unsubscribe' | 'bounce' | 'complaint' | 'manual'
            $table->string('reason', 20)->index();

            // Free-form provenance: the Mailgun event id, or the admin who added
            // it manually. Never holds credential material.
            $table->string('detail', 255)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Dropping this loses opt-out records, so it is documented as
        // forward-only in practice: down() exists for local rollback of an
        // unreleased change, and production never rolls back.
        Schema::dropIfExists('mailgun_suppressions');
    }
};
