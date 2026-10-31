<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The real people who write and check a site's content.
 *
 * Per site, like articles and CMS pages: an author page is part of one brand's
 * trust story, and the same person appearing under eleven brands would be the
 * opposite of a trust signal.
 *
 * `visible` defaults to FALSE. An author row is created before its bio and photo
 * are ready, and nothing about a person should publish until someone decides it
 * should — the same rule CMS pages follow with `draft`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('authors')) {
            return;
        }

        Schema::create('authors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('slug', 140);
            $table->string('photo_path', 500)->nullable();
            $table->string('role', 120)->nullable();
            $table->string('short_bio', 300)->nullable();
            $table->text('long_bio')->nullable();
            // ["Bonus terms", "Payments"] — free-form labels, rendered as tags.
            $table->json('expertise')->nullable();
            // [{"label": "LinkedIn", "url": "https://…"}] — becomes Person.sameAs.
            $table->json('profile_links')->nullable();
            $table->boolean('visible')->default(false);
            $table->timestamps();

            // The public URL is /authors/{slug}, resolved within one site.
            $table->unique(['site_id', 'slug']);
            $table->index(['site_id', 'visible']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('authors');
    }
};
