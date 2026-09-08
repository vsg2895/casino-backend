<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Visitor-written reviews of a casino, submitted on one site.
 *
 * BOTH keys are load-bearing and neither is redundant. `casino_id` is what the
 * review is about; `site_id` is where it was written. A casino is attached to
 * many sites, so without `site_id` a review left on one domain would surface on
 * every sibling domain showing the same casino — which is both wrong and an
 * obvious duplicate-content problem across a network of affiliate sites.
 *
 * `status` rather than a boolean: "never looked at" and "looked at and rejected"
 * are different states, and collapsing them loses the moderation queue. A
 * publicly submitted form on an affiliate site attracts spam, so nothing is
 * visible until an admin publishes it — see STATUS_PENDING being the default.
 *
 * There is no soft delete. The admin's delete is permanent by design, so a
 * removed review cannot come back and cannot sit in the table as a row nothing
 * will ever show again.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('casino_reviews')) {
            return;
        }

        Schema::create('casino_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('casino_id')->constrained()->cascadeOnDelete();

            $table->string('author_name', 120);
            // Optional, never returned by the public API — it exists so an admin
            // can recognise a repeat spammer, nothing more.
            $table->string('author_email', 255)->nullable();

            $table->unsignedTinyInteger('rating');
            $table->string('title', 160)->nullable();
            $table->text('body');

            $table->string('status', 12)->default('pending');
            // When it became publicly visible. Null while pending or hidden, so
            // "published and when" is one column rather than two.
            $table->timestamp('published_at')->nullable();

            $table->timestamps();

            // The public listing: this casino, on this site, published, newest
            // first. Ordered to match so the query needs no filesort.
            $table->index(['casino_id', 'site_id', 'status', 'created_at'], 'casino_reviews_public_idx');
            // The admin queue: everything awaiting moderation, newest first.
            $table->index(['status', 'created_at']);
            $table->index('site_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('casino_reviews');
    }
};
