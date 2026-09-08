<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Structured bonus terms on an offer.
 *
 * `bonuses` today is a single free-text headline ("500$ + 180 Free Spins") —
 * good for scanning, useless for comparing. These columns are the terms a player
 * actually needs before depositing, and the ones an affiliate site is expected
 * to state plainly rather than bury in the operator's T&Cs.
 *
 * ROADMAP DEVIATION, deliberate: the plan specified `min_deposit` and
 * `max_cashout` as DECIMAL. They are strings here, for the same reason the
 * operator-profile money fields are. A decimal carries no currency, and these
 * offers run in EUR, USD and crypto — rendering "20" next to a bonus is either
 * meaningless or, worse, read as the wrong currency. Operators also state them
 * as ranges ("€20, €50 by wire"), which a decimal column simply cannot hold.
 *
 * `expires_at` is a COMPLIANCE field, not a convenience. An offer past its
 * expiry must stop being presented as claimable, so it is excluded from every
 * listing and rendered without a call to action on its own page.
 *
 * Every column is nullable. An offer with no structured terms renders exactly as
 * it does today.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('special_offers')) {
            return;
        }

        Schema::table('special_offers', function (Blueprint $table): void {
            if (! Schema::hasColumn('special_offers', 'wagering_requirement')) {
                // "35x (D+B)" is not a number. The multiplier, the base it
                // applies to and any exclusion are one inseparable statement.
                $table->string('wagering_requirement', 120)->nullable();
            }

            if (! Schema::hasColumn('special_offers', 'min_deposit')) {
                $table->string('min_deposit', 120)->nullable();
            }

            if (! Schema::hasColumn('special_offers', 'max_cashout')) {
                $table->string('max_cashout', 120)->nullable();
            }

            if (! Schema::hasColumn('special_offers', 'bonus_code')) {
                $table->string('bonus_code', 60)->nullable();
            }

            if (! Schema::hasColumn('special_offers', 'expires_at')) {
                // DATE, not datetime: operators publish an end date, not an
                // instant, and pretending to a precision we were never given
                // would expire offers hours early in some timezone.
                $table->date('expires_at')->nullable();
                // Listings filter on it alongside `active`.
                $table->index(['active', 'expires_at'], 'special_offers_claimable_index');
            }

            if (! Schema::hasColumn('special_offers', 'terms_url')) {
                $table->string('terms_url', 500)->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('special_offers')) {
            return;
        }

        Schema::table('special_offers', function (Blueprint $table): void {
            if (Schema::hasColumn('special_offers', 'expires_at')) {
                $table->dropIndex('special_offers_claimable_index');
            }

            foreach (['wagering_requirement', 'min_deposit', 'max_cashout', 'bonus_code', 'expires_at', 'terms_url'] as $column) {
                if (Schema::hasColumn('special_offers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
