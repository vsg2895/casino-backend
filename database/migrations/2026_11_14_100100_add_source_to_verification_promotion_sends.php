<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records WHICH audience a post-verification promotion went to.
 *
 * Until now every row in this table came from one place — a newsletter
 * subscriber who verified — so `newsletter_id` identified the recipient and no
 * label was needed. Registered members now receive the same email after
 * verifying their account, and they have no newsletter row at all.
 *
 * `source` rather than inferring it from `newsletter_id IS NULL`: that column is
 * already nullable for a reason the comment on it gives — a deleted subscriber
 * nulls the FK while the row survives as history. A null FK therefore already
 * means "subscriber since removed", and reusing it to also mean "was never a
 * subscriber" would make the history ambiguous exactly where it is being read
 * to answer a complaint.
 *
 * `forum_user_id` is nullable and nulls on delete, the same way `newsletter_id`
 * does, so deleting a member leaves the send record intact — the row is proof
 * that an email was sent, and it has to outlive the account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('verification_promotion_sends', function (Blueprint $table): void {
            $table->string('source', 16)->default('newsletter')->after('newsletter_id');
            $table->foreignId('forum_user_id')->nullable()->after('source')
                ->constrained('forum_users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('verification_promotion_sends', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('forum_user_id');
            $table->dropColumn('source');
        });
    }
};
