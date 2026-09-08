<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The factual profile of one casino — licence, payments, support, safer-play
 * tools.
 *
 * A one-to-one companion table rather than thirty more columns on `casinos`.
 * `casinos` is the hottest table in the application: every public listing reads
 * it through `casino_site`, and widening the row makes every one of those reads
 * carry fields that only the detail page uses.
 *
 * List-shaped fields (licences, currencies, payment methods, providers, support
 * languages) are JSON arrays of strings, edited as chips in the admin. Lookup
 * tables would buy referential integrity we do not need yet and cost four more
 * screens; a JSON array is still fully admin-editable, which is what the
 * admin-first contract actually asks for. When faceted filtering arrives and
 * these need to be queryable, normalising them is a migration, not a redesign.
 *
 * The six safer-play tools are BOOLEANS, not a JSON blob, precisely because they
 * will be filtered on — and because winpalack's whole positioning is player
 * protection, so they are the fields most likely to become a ranking signal.
 *
 * Every column is nullable. A half-known operator is the normal case, and the
 * public page renders only the groups that have values.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('casino_details')) {
            return;
        }

        Schema::create('casino_details', function (Blueprint $table): void {
            $table->id();

            // Unique, not just indexed: one profile per casino is the whole
            // shape of this table, and the database should say so.
            $table->foreignId('casino_id')->unique()->constrained()->cascadeOnDelete();

            // ── General ──────────────────────────────────────────────────────
            $table->unsignedSmallInteger('established_year')->nullable();
            $table->string('company', 255)->nullable();
            $table->json('licences')->nullable();          // ["MGA", "Curaçao"]
            $table->json('currencies')->nullable();        // ["EUR", "USD"]

            // ── Payments ─────────────────────────────────────────────────────
            $table->json('payment_methods')->nullable();   // ["Visa", "Skrill"]
            // Free text, not decimals: real operators state these as ranges and
            // per-method values ("€20, €50 by wire"), and forcing a number would
            // make the field a lie in the common case.
            $table->string('min_deposit', 120)->nullable();
            $table->string('min_withdrawal', 120)->nullable();
            $table->string('withdrawal_limit', 120)->nullable();
            $table->string('pending_time', 120)->nullable();
            $table->string('withdrawal_time', 120)->nullable();
            $table->string('verification_speed', 120)->nullable();
            $table->boolean('deposit_fees')->nullable();
            $table->boolean('withdrawal_fees')->nullable();

            // ── Games ────────────────────────────────────────────────────────
            $table->json('game_providers')->nullable();
            $table->boolean('rng_tested')->nullable();
            $table->boolean('progressive_jackpots')->nullable();

            // ── Support ──────────────────────────────────────────────────────
            $table->boolean('live_chat')->nullable();
            $table->boolean('email_support')->nullable();
            $table->string('support_email', 255)->nullable();
            $table->json('support_languages')->nullable();

            // ── Safer play ───────────────────────────────────────────────────
            // Booleans, not JSON: these get filtered on, and they are the fields
            // winpalack's positioning rests on.
            $table->boolean('tool_deposit_limit')->nullable();
            $table->boolean('tool_loss_limit')->nullable();
            $table->boolean('tool_session_limit')->nullable();
            $table->boolean('tool_reality_check')->nullable();
            $table->boolean('tool_withdrawal_lock')->nullable();
            $table->boolean('tool_self_exclusion')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('casino_details');
    }
};
