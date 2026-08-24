<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Two more opaque, PII-free unsubscribe tokens so EACH template a subscriber can
 * receive carries its own token, and the opt-out it produces can be attributed
 * to the exact template it came from:
 *   - `unsubscribe_token`                        → subscription confirmation
 *   - `promotion_unsubscribe_token`              → promotion campaign
 *   - `verify_unsubscribe_token`                 → verify (double opt-in) email   [added here]
 *   - `verification_promotion_unsubscribe_token` → promotion-after-verification   [added here]
 *
 * The token is only ever used to DETECT which stream an unsubscribe came from
 * (recorded as the `unsubscribes.type`); the actual send-gating is global — any
 * opt-out stops all mail to that address — so a subscriber still needs just one
 * click to leave, but the admin can now see which email prompted it.
 *
 * The verify token is a NEW, independent secret rather than the subscription
 * token the verify email used to share: the verify *link* still uses the
 * subscription token (VerifyController resolves by `unsubscribe_token`), so the
 * two must not be the same value or a one-click unsubscribe could not be told
 * apart from a verify click.
 *
 * Existing rows (including soft-deleted) are backfilled so every subscriber can
 * be unsubscribed from either new stream immediately.
 *
 * The `unsubscribes.type` column is widened from 20 to 40 chars: the new value
 * `promotion_after_verification` is 28 characters and would not otherwise fit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('newsletters', function (Blueprint $table): void {
            $table->string('verify_unsubscribe_token', 64)
                ->nullable()
                ->unique()
                ->after('promotion_unsubscribe_token');

            $table->string('verification_promotion_unsubscribe_token', 64)
                ->nullable()
                ->unique()
                ->after('verify_unsubscribe_token');
        });

        // Backfill both tokens for every existing subscriber (incl. soft-deleted).
        DB::table('newsletters')
            ->where(function ($q): void {
                $q->whereNull('verify_unsubscribe_token')
                    ->orWhereNull('verification_promotion_unsubscribe_token');
            })
            ->orderBy('id')
            ->each(function (object $row): void {
                $update = [];
                if ($row->verify_unsubscribe_token === null) {
                    $update['verify_unsubscribe_token'] = Str::random(64);
                }
                if ($row->verification_promotion_unsubscribe_token === null) {
                    $update['verification_promotion_unsubscribe_token'] = Str::random(64);
                }

                if ($update !== []) {
                    DB::table('newsletters')->where('id', $row->id)->update($update);
                }
            });

        // Widen `unsubscribes.type` so the longer category codes fit.
        Schema::table('unsubscribes', function (Blueprint $table): void {
            $table->string('type', 40)->change();
        });
    }

    public function down(): void
    {
        Schema::table('newsletters', function (Blueprint $table): void {
            $table->dropUnique(['verify_unsubscribe_token']);
            $table->dropUnique(['verification_promotion_unsubscribe_token']);
            $table->dropColumn(['verify_unsubscribe_token', 'verification_promotion_unsubscribe_token']);
        });

        Schema::table('unsubscribes', function (Blueprint $table): void {
            $table->string('type', 20)->change();
        });
    }
};
