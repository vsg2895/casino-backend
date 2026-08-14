<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Columns the warmup send needs, plus an audit row per run.
 *
 * Deliberately NOT added to `warmup_emails`: site_id, template, recipient_count.
 * Those describe a SEND REQUEST, not an address — storing them per address would
 * rewrite every row on every run and make "which template did this address last
 * receive" unanswerable. They live on `warmup_sends` instead.
 *
 * No `unsubscribe_token` either: warmup renders a template through each mail
 * service's previewMail(), which mints its own well-formed sample token. Storing
 * a real one would only pay off if the public unsubscribe endpoint learned to
 * resolve warmup addresses — existing logic this change is not allowed to touch.
 *
 * Nothing here touches the promotion tables. The warmup feature mirrors the
 * promotion pipeline's shape but owns its own schema, so the scheduled-campaign
 * path is unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warmup_emails', function (Blueprint $table): void {
            // Drives least-recently-contacted rotation. NULL = never contacted;
            // MySQL sorts NULLs first ascending, so a newly imported address
            // enters the rotation ahead of everything already warmed.
            $table->timestamp('last_sent_at')->nullable()->after('email');

            // Exactly the picker's ORDER BY: rotation position, then id as the
            // tiebreaker so chunked reads can never repeat or skip an address.
            $table->index(['last_sent_at', 'id'], 'warmup_emails_rotation_index');
        });

        // One row per warmup run — what was sent, to how many, through which
        // site's template. Mirrors `newsletter_imports` / `phone_sms_histories`.
        Schema::create('warmup_sends', function (Blueprint $table): void {
            $table->id();

            // Which site's template was rendered. nullOnDelete: the audit must
            // outlive the site being removed.
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // EmailTemplateCatalog key: subscribe | promotion.
            $table->string('template', 20);

            // What the admin asked for (null = "all addresses") and what was
            // actually queued after the rotation picker ran.
            $table->unsignedInteger('requested_count')->nullable();
            $table->unsignedInteger('queued_count')->default(0);

            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warmup_sends');

        Schema::table('warmup_emails', function (Blueprint $table): void {
            $table->dropIndex('warmup_emails_rotation_index');
            $table->dropColumn('last_sent_at');
        });
    }
};
