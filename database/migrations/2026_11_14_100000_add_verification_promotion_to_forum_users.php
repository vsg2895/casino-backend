<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Lets a REGISTERED member receive the post-verification promotion, the same way
 * a newsletter subscriber already does.
 *
 * The trigger already exists: `forum_users.email_verified_at` is stamped by
 * ForumAuthController::verify() when the member clicks the link in their
 * registration email, and by the Google path when Google vouches for the
 * address. What was missing is everything after that moment.
 *
 * TWO COLUMNS, for two different jobs.
 *
 * `verification_promotion_sent_at` is the CLAIM MARKER. The sweep finds members
 * whose delay has elapsed and the send job claims each one with a conditional
 * update — `WHERE verification_promotion_sent_at IS NULL`, which is atomic in
 * InnoDB — so two workers racing the same member cannot both send. Exactly the
 * mechanism `newsletters.verification_promotion_sent_at` provides on the other
 * side.
 *
 * `verification_promotion_unsubscribe_token` is the one that makes this legal to
 * send. The public unsubscribe endpoint resolves a token through
 * Newsletter::findByUnsubscribeToken, and a registered member has no newsletter
 * row — so without a token of their own, the unsubscribe link in their email
 * would resolve to nothing. A marketing email whose opt-out does not work is not
 * a bug to fix later.
 *
 * WHY NOT CREATE A NEWSLETTER ROW instead, which would have given both for free:
 * because registering an account is not subscribing to a mailing list. A member
 * who never ticked the newsletter box would appear in the subscriber list, in
 * its counts and exports, and in every future campaign. Keeping the two
 * audiences separate is the point; they share only the template.
 *
 * BACKFILL: every existing member gets a token, and members who verified BEFORE
 * this migration are marked as already sent. That second part matters — without
 * it, the first sweep after deploy would mail every member who ever verified, in
 * one burst. The feature starts from now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forum_users', function (Blueprint $table): void {
            $table->timestamp('verification_promotion_sent_at')->nullable()->after('email_verified_at');
            $table->string('verification_promotion_unsubscribe_token', 64)->nullable()->after('verification_promotion_sent_at');
        });

        // Unique so a token can identify exactly one member, and so a collision
        // is a write error rather than two members sharing an opt-out link.
        Schema::table('forum_users', function (Blueprint $table): void {
            $table->unique('verification_promotion_unsubscribe_token', 'forum_users_vp_token_unique');

            /*
             * The sweep's index. It filters on
             * `verification_promotion_sent_at IS NULL AND email_verified_at <= ?`
             * and runs every minute from the scheduler, so it must not be a
             * table scan once the member table grows.
             */
            $table->index(
                ['site_id', 'verification_promotion_sent_at', 'email_verified_at'],
                'forum_users_vp_sweep_idx',
            );
        });

        /*
         * A token for everyone who already exists. Generated row by row rather
         * than with one SQL expression: these have to be unguessable, and
         * MySQL's RAND() is not a source of security tokens.
         */
        DB::table('forum_users')
            ->select('id')
            ->orderBy('id')
            ->chunk(500, function ($members): void {
                foreach ($members as $member) {
                    DB::table('forum_users')
                        ->where('id', $member->id)
                        ->update(['verification_promotion_unsubscribe_token' => Str::random(64)]);
                }
            });

        /*
         * Everyone already verified is treated as already sent.
         *
         * `email_verified_at` is used as the timestamp rather than now(), so the
         * history reads honestly: these members were never mailed, and the
         * marker says "claimed", not "delivered". Nothing writes a send record
         * for them.
         */
        DB::table('forum_users')
            ->whereNotNull('email_verified_at')
            ->whereNull('verification_promotion_sent_at')
            ->update(['verification_promotion_sent_at' => DB::raw('email_verified_at')]);
    }

    public function down(): void
    {
        Schema::table('forum_users', function (Blueprint $table): void {
            $table->dropUnique('forum_users_vp_token_unique');
            $table->dropIndex('forum_users_vp_sweep_idx');
            $table->dropColumn(['verification_promotion_sent_at', 'verification_promotion_unsubscribe_token']);
        });
    }
};
