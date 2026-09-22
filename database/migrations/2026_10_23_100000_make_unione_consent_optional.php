<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consent becomes optional on UniOne receivers.
 *
 * ── What changed and why ────────────────────────────────────────────────────
 *
 * These two columns were NOT NULL and were required by `scopeSendable()`, so an
 * address without a recorded consent source and date could neither be imported
 * nor mailed. That was the original requirement.
 *
 * It has been relaxed at the operator's request: the import now takes a file and
 * nothing else, matching the Warmup receivers import exactly.
 *
 * ── What this costs, recorded here because the column can no longer say it ──
 *
 * UniOne's terms require documented consent for recipients. Nothing in this
 * application now enforces that — the consent fields survive as OPTIONAL
 * metadata an operator may still fill in from the receiver editor, but no send
 * path checks them. Whether a given address consented is now a question only the
 * operator can answer, and the answer is not in this database unless they put it
 * there.
 *
 * The columns are kept rather than dropped: existing rows carry real consent
 * records, and deleting them would destroy evidence that is useful precisely
 * when an account is under review.
 *
 * ── Scope of the ALTER ──────────────────────────────────────────────────────
 *
 * This alters `unione_receivers`, a table this feature created. No pre-existing
 * platform table is touched, which is the isolation rule that matters.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('unione_receivers')) {
            return;
        }

        Schema::table('unione_receivers', function (Blueprint $table): void {
            $table->string('consent_source', 120)->nullable()->change();
            $table->timestamp('consent_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('unione_receivers')) {
            return;
        }

        /*
         * Reversing needs a value for every NULL, or the NOT NULL fails.
         * Backfilling an invented consent record would be worse than refusing:
         * it would manufacture the exact evidence this column exists to hold.
         * So the down path restores the constraint only when nothing violates it.
         */
        $unrecorded = \Illuminate\Support\Facades\DB::table('unione_receivers')
            ->whereNull('consent_source')
            ->orWhereNull('consent_at')
            ->count();

        if ($unrecorded > 0) {
            throw new RuntimeException(
                "Cannot restore the consent constraint: {$unrecorded} receiver(s) have no consent on record. "
                . 'Fill them in or remove them first — this migration will not invent consent records.',
            );
        }

        Schema::table('unione_receivers', function (Blueprint $table): void {
            $table->string('consent_source', 120)->nullable(false)->change();
            $table->timestamp('consent_at')->nullable(false)->change();
        });
    }
};
